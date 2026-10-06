<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Security\AuditLogger;
use App\Security\Crypto;
use App\Security\PasswordHasher;
use App\Security\RateLimiter;
use App\Security\Rbac;
use App\Security\Tokens;
use App\Security\Totp;
use App\Services\Notifications\NotificationService;

final class AuthService
{
    private const GENERIC_FAILURE = 'The email or password is incorrect.';

    public function __construct(
        private Database $db,
        private Clock $clock,
        private Config $config,
        private PasswordHasher $hasher,
        private SessionService $sessions,
        private RateLimiter $limiter,
        private AuditLogger $audit,
        private Crypto $crypto,
        private Rbac $rbac,
        private NotificationService $notifications,
    ) {
    }

    /**
     * @return array{status: 'authenticated', session: array{token: string, csrf: string, expires_at: string}, user_id: int}
     *       | array{status: 'two_factor_required', challenge: string}
     */
    public function login(string $email, string $password, Request $request): array
    {
        $email = mb_strtolower(trim($email));
        $ip = $request->ip($this->config->get('app.trusted_proxies', []));

        $wait = max(
            $this->limiter->hit('login:email', $email, 10, 900),
            $this->limiter->hit('login:ip', $ip, 30, 900),
        );
        if ($wait > 0) {
            throw new HttpException(429, 'rate_limited', 'Too many sign-in attempts. Please try again later.', [], ['Retry-After' => (string) $wait]);
        }

        $user = $this->db->first('SELECT * FROM users WHERE email = ?', [$email]);
        if ($user === null) {
            $this->hasher->dummyVerify();
            $this->audit->record(null, 'auth.login_failed', 'user', null, ['reason' => 'unknown_account'], $request);
            throw new HttpException(401, 'invalid_credentials', self::GENERIC_FAILURE);
        }

        $now = $this->clock->now();
        if ($user['locked_until'] !== null && Clock::utc((string) $user['locked_until']) > $now) {
            $this->hasher->dummyVerify();
            throw new HttpException(423, 'account_locked', 'This account is temporarily locked after repeated failed attempts. Please try again later or reset your password.');
        }

        if ($user['status'] !== 'active' || !$this->hasher->verify($password, (string) $user['password_hash'])) {
            $this->registerFailure($user, $request);
            throw new HttpException(401, 'invalid_credentials', self::GENERIC_FAILURE);
        }

        if ($this->hasher->needsRehash((string) $user['password_hash'])) {
            $this->db->update('users', ['password_hash' => $this->hasher->hash($password)], ['id' => $user['id']]);
        }

        $this->db->update('users', ['failed_logins' => 0, 'locked_until' => null, 'lockouts' => 0], ['id' => $user['id']]);
        $this->limiter->clear('login:email', $email);

        if ((bool) $user['totp_enabled']) {
            $challenge = Tokens::random(32);
            $this->db->insert('login_challenges', [
                'user_id' => $user['id'],
                'token_hash' => Tokens::hash($challenge),
                'expires_at' => $now->modify('+5 minutes')->format('Y-m-d H:i:s'),
                'created_at' => $now->format('Y-m-d H:i:s'),
            ]);

            return ['status' => 'two_factor_required', 'challenge' => $challenge];
        }

        return $this->completeLogin((int) $user['id'], $request);
    }

    /** @return array{status: 'authenticated', session: array{token: string, csrf: string, expires_at: string}, user_id: int} */
    public function verifyTwoFactor(string $challenge, string $code, Request $request): array
    {
        $row = $this->db->first(
            'SELECT c.*, u.totp_secret_enc FROM login_challenges c JOIN users u ON u.id = c.user_id WHERE c.token_hash = ?',
            [Tokens::hash($challenge)],
        );
        $now = $this->clock->now();
        if ($row === null || Clock::utc((string) $row['expires_at']) < $now || (int) $row['attempts'] >= 5) {
            throw new HttpException(401, 'challenge_expired', 'Your verification window has expired. Please sign in again.');
        }

        $secret = $this->crypto->decrypt($row['totp_secret_enc'], 'users.totp_secret');
        if ($secret === null || !Totp::verify($secret, $code)) {
            $this->db->run('UPDATE login_challenges SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
            $this->audit->record((int) $row['user_id'], 'auth.two_factor_failed', 'user', $row['user_id'], [], $request);
            throw HttpException::validation(['code' => ['The verification code is not valid.']]);
        }

        $this->db->delete('login_challenges', ['id' => $row['id']]);

        return $this->completeLogin((int) $row['user_id'], $request);
    }

    public function requestPasswordReset(string $email, Request $request): void
    {
        $email = mb_strtolower(trim($email));
        if ($this->limiter->hit('reset:email', $email, 3, 3600) > 0) {
            return; // Silently drop: response is always 202 to avoid enumeration.
        }
        $user = $this->db->first("SELECT id, name, email FROM users WHERE email = ? AND status = 'active'", [$email]);
        if ($user === null) {
            return;
        }
        $token = Tokens::random(32);
        $now = $this->clock->now();
        $this->db->insert('password_resets', [
            'user_id' => $user['id'],
            'token_hash' => Tokens::hash($token),
            'expires_at' => $now->modify('+30 minutes')->format('Y-m-d H:i:s'),
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);
        $this->notifications->queue('password_reset', (string) $user['email'], [
            'name' => $user['name'],
            'reset_url' => $this->config->get('app.admin_url') . '/reset-password?token=' . rawurlencode($token),
        ], 'user', (int) $user['id']);
        $this->audit->record((int) $user['id'], 'auth.password_reset_requested', 'user', $user['id'], [], $request);
    }

    public function resetPassword(string $token, string $password, Request $request): void
    {
        $row = $this->db->first(
            'SELECT r.*, u.email FROM password_resets r JOIN users u ON u.id = r.user_id WHERE r.token_hash = ? AND r.used_at IS NULL',
            [Tokens::hash($token)],
        );
        if ($row === null || Clock::utc((string) $row['expires_at']) < $this->clock->now()) {
            throw HttpException::validation(['token' => ['This reset link is invalid or has expired.']]);
        }
        $this->hasher->assertStrong($password, 'password', (string) $row['email']);

        $this->db->transaction(function () use ($row, $password) {
            $now = $this->clock->nowString();
            $this->db->update('password_resets', ['used_at' => $now], ['id' => $row['id']]);
            $this->db->update('users', [
                'password_hash' => $this->hasher->hash($password),
                'password_changed_at' => $now,
                'failed_logins' => 0,
                'locked_until' => null,
                'lockouts' => 0,
                'updated_at' => $now,
            ], ['id' => $row['user_id']]);
            $this->sessions->revokeAllForUser((int) $row['user_id']);
        });
        $this->audit->record((int) $row['user_id'], 'auth.password_reset', 'user', $row['user_id'], [], $request);
    }

    public function changePassword(int $userId, int $sessionId, string $current, string $password, Request $request): void
    {
        $user = $this->db->first('SELECT * FROM users WHERE id = ?', [$userId]) ?? throw HttpException::notFound();
        if (!$this->hasher->verify($current, (string) $user['password_hash'])) {
            throw HttpException::validation(['current_password' => ['Your current password is incorrect.']]);
        }
        $this->hasher->assertStrong($password, 'password', (string) $user['email']);
        $now = $this->clock->nowString();
        $this->db->update('users', ['password_hash' => $this->hasher->hash($password), 'password_changed_at' => $now, 'updated_at' => $now], ['id' => $userId]);
        $this->sessions->revokeAllForUser($userId, $sessionId);
        $this->audit->record($userId, 'auth.password_changed', 'user', $userId, [], $request);
    }

    /** @return array{secret: string, uri: string} */
    public function beginTwoFactorSetup(int $userId): array
    {
        $user = $this->db->first('SELECT email, totp_enabled FROM users WHERE id = ?', [$userId]) ?? throw HttpException::notFound();
        if ((bool) $user['totp_enabled']) {
            throw HttpException::conflict('two_factor_enabled', 'Two-factor authentication is already enabled.');
        }
        $secret = Totp::generateSecret();
        $this->db->update('users', ['totp_secret_enc' => $this->crypto->encrypt($secret, 'users.totp_secret')], ['id' => $userId]);

        return ['secret' => $secret, 'uri' => Totp::provisioningUri($secret, (string) $user['email'], (string) $this->config->get('app.name'))];
    }

    public function enableTwoFactor(int $userId, string $code, Request $request): void
    {
        $user = $this->db->first('SELECT totp_secret_enc FROM users WHERE id = ?', [$userId]) ?? throw HttpException::notFound();
        $secret = $this->crypto->decrypt($user['totp_secret_enc'], 'users.totp_secret');
        if ($secret === null || !Totp::verify($secret, $code)) {
            throw HttpException::validation(['code' => ['The verification code is not valid.']]);
        }
        $this->db->update('users', ['totp_enabled' => 1], ['id' => $userId]);
        $this->audit->record($userId, 'auth.two_factor_enabled', 'user', $userId, [], $request);
    }

    public function disableTwoFactor(int $userId, string $password, Request $request): void
    {
        if ($this->config->get('security.require_two_factor')) {
            throw HttpException::conflict('two_factor_required', 'Two-factor authentication is required for administrators and cannot be turned off. Ask a Super Admin to reset it if you have lost your device.');
        }
        $user = $this->db->first('SELECT password_hash FROM users WHERE id = ?', [$userId]) ?? throw HttpException::notFound();
        if (!$this->hasher->verify($password, (string) $user['password_hash'])) {
            throw HttpException::validation(['password' => ['Your password is incorrect.']]);
        }
        $this->db->update('users', ['totp_enabled' => 0, 'totp_secret_enc' => null], ['id' => $userId]);
        $this->audit->record($userId, 'auth.two_factor_disabled', 'user', $userId, [], $request);
    }

    /** @return array<string, mixed> */
    public function profile(int $userId): array
    {
        $user = $this->db->first('SELECT id, email, name, totp_enabled, last_login_at FROM users WHERE id = ?', [$userId]) ?? throw HttpException::notFound();

        return [
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'name' => $user['name'],
            'two_factor_enabled' => (bool) $user['totp_enabled'],
            'two_factor_required' => (bool) $this->config->get('security.require_two_factor'),
            'last_login_at' => Clock::iso($user['last_login_at']),
            'roles' => $this->rbac->rolesFor($userId),
            'permissions' => $this->rbac->permissionsFor($userId),
        ];
    }

    /** @return array{status: 'authenticated', session: array{token: string, csrf: string, expires_at: string}, user_id: int} */
    private function completeLogin(int $userId, Request $request): array
    {
        $session = $this->sessions->create($userId, $request);
        $this->db->update('users', ['last_login_at' => $this->clock->nowString()], ['id' => $userId]);
        $this->audit->record($userId, 'auth.login', 'user', $userId, [], $request);

        return ['status' => 'authenticated', 'session' => $session, 'user_id' => $userId];
    }

    /** @param array<string, mixed> $user */
    private function registerFailure(array $user, Request $request): void
    {
        $failures = (int) $user['failed_logins'] + 1;
        $update = ['failed_logins' => $failures];
        $threshold = (int) $this->config->get('security.lockout.threshold');

        if ($failures >= $threshold) {
            $lockouts = (int) $user['lockouts'] + 1;
            $minutes = min(
                (int) $this->config->get('security.lockout.base_minutes') * (2 ** ($lockouts - 1)),
                (int) $this->config->get('security.lockout.max_minutes'),
            );
            $update = [
                'failed_logins' => 0,
                'lockouts' => $lockouts,
                'locked_until' => $this->clock->now()->modify("+{$minutes} minutes")->format('Y-m-d H:i:s'),
            ];
            $this->audit->record((int) $user['id'], 'auth.locked', 'user', $user['id'], ['minutes' => $minutes], $request);
        }

        $this->db->update('users', $update, ['id' => $user['id']]);
        $this->audit->record((int) $user['id'], 'auth.login_failed', 'user', $user['id'], [], $request);
    }
}

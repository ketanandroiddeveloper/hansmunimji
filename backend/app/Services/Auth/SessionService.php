<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Security\Tokens;

final class SessionService
{
    public function __construct(private Database $db, private Clock $clock, private Config $config)
    {
    }

    /** @return array{token: string, csrf: string, expires_at: string} */
    public function create(int $userId, Request $request): array
    {
        $token = Tokens::random(32);
        $csrf = Tokens::random(32);
        $now = $this->clock->now();
        $expires = $now->modify('+' . (int) $this->config->get('security.session.absolute_hours') . ' hours');

        $this->db->insert('user_sessions', [
            'user_id' => $userId,
            'token_hash' => Tokens::hash($token),
            'csrf_hash' => Tokens::hash($csrf),
            'ip_hash' => hash_hmac('sha256', $request->ip($this->config->get('app.trusted_proxies', [])), (string) $this->config->get('security.app_key')),
            'user_agent' => $request->userAgent(),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'last_seen_at' => $now->format('Y-m-d H:i:s'),
            'expires_at' => $expires->format('Y-m-d H:i:s'),
        ]);

        return ['token' => $token, 'csrf' => $csrf, 'expires_at' => $expires->format('Y-m-d H:i:s')];
    }

    /** @return array<string, mixed>|null active session joined with an active user */
    public function resolve(string $token): ?array
    {
        $session = $this->db->first(
            "SELECT s.*, u.status AS user_status FROM user_sessions s JOIN users u ON u.id = s.user_id
             WHERE s.token_hash = ? AND s.revoked_at IS NULL",
            [Tokens::hash($token)],
        );
        if ($session === null || $session['user_status'] !== 'active') {
            return null;
        }

        $now = $this->clock->now();
        $idleLimit = Clock::utc((string) $session['last_seen_at'])->modify('+' . (int) $this->config->get('security.session.idle_minutes') . ' minutes');
        if ($now >= Clock::utc((string) $session['expires_at']) || $now >= $idleLimit) {
            $this->db->update('user_sessions', ['revoked_at' => $now->format('Y-m-d H:i:s')], ['id' => $session['id']]);

            return null;
        }

        if ($now->getTimestamp() - Clock::utc((string) $session['last_seen_at'])->getTimestamp() > 60) {
            $this->db->update('user_sessions', ['last_seen_at' => $now->format('Y-m-d H:i:s')], ['id' => $session['id']]);
        }

        return $session;
    }

    /** Issues a new token for the same session lineage and revokes the old one. */
    public function rotate(array $session, Request $request): array
    {
        $this->revoke((int) $session['id']);

        return $this->create((int) $session['user_id'], $request);
    }

    public function revoke(int $sessionId): void
    {
        $this->db->update('user_sessions', ['revoked_at' => $this->clock->nowString()], ['id' => $sessionId]);
    }

    public function revokeAllForUser(int $userId, ?int $exceptSessionId = null): void
    {
        $this->db->run(
            'UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL AND id <> ?',
            [$this->clock->nowString(), $userId, $exceptSessionId ?? 0],
        );
    }

    /** Rotates the CSRF token for an existing session (used by /auth/me after reload). */
    public function issueCsrf(int $sessionId): string
    {
        $csrf = Tokens::random(32);
        $this->db->update('user_sessions', ['csrf_hash' => Tokens::hash($csrf)], ['id' => $sessionId]);

        return $csrf;
    }

    public function attachCookie(Response $response, string $token): Response
    {
        $hours = (int) $this->config->get('security.session.absolute_hours');

        return $response->cookie(
            (string) $this->config->get('security.session.cookie'),
            $token,
            time() + $hours * 3600,
            (bool) $this->config->get('security.session.secure'),
        );
    }

    public function clearCookie(Response $response): Response
    {
        return $response->cookie(
            (string) $this->config->get('security.session.cookie'),
            '',
            -1,
            (bool) $this->config->get('security.session.secure'),
        );
    }
}

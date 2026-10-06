<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Security\AuditLogger;
use App\Security\PasswordHasher;
use App\Security\Rbac;
use App\Security\Tokens;
use App\Services\Auth\SessionService;
use App\Services\Notifications\NotificationService;

/**
 * Administrator accounts. New users never receive a password from us: an unusable random hash is
 * stored and an invitation link lets them set their own.
 */
final class UsersController extends Controller
{
    public function __construct(
        private Database $db,
        private Clock $clock,
        private Config $config,
        private Rbac $rbac,
        private PasswordHasher $hasher,
        private SessionService $sessions,
        private NotificationService $notifications,
        private AuditLogger $audit,
    ) {
    }

    public function index(Request $request): Response
    {
        $users = $this->db->all('SELECT id, email, name, status, totp_enabled, last_login_at, locked_until, created_at FROM users ORDER BY name');
        foreach ($users as &$u) {
            $u['roles'] = $this->rbac->rolesFor((int) $u['id']);
            $u['totp_enabled'] = (bool) $u['totp_enabled'];
            foreach (['last_login_at', 'locked_until', 'created_at'] as $f) {
                $u[$f] = Clock::iso($u[$f]);
            }
        }

        return $this->ok($users);
    }

    public function roles(Request $request): Response
    {
        $roles = $this->db->all('SELECT id, slug, name, description FROM roles ORDER BY id');
        foreach ($roles as &$role) {
            $role['permissions'] = $role['slug'] === 'super-admin'
                ? ['*']
                : array_column($this->db->all('SELECT p.slug FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.slug', [$role['id']]), 'slug');
        }

        return $this->ok(['roles' => $roles, 'permissions' => Rbac::PERMISSIONS]);
    }

    public function store(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['email' => 'required|email', 'name' => 'required|string|min:2|max:190', 'roles' => 'required|array']);
        $email = mb_strtolower(trim((string) $data['email']));
        if ($this->db->value('SELECT 1 FROM users WHERE email = ?', [$email])) {
            throw HttpException::validation(['email' => ['An administrator with this email already exists.']]);
        }
        $roleIds = $this->resolveRoles($data['roles'], $this->userId($request));
        $now = $this->clock->now();

        $id = $this->db->transaction(function () use ($email, $data, $roleIds, $now) {
            $id = $this->db->insert('users', [
                'email' => $email,
                'name' => $data['name'],
                'password_hash' => $this->hasher->hash(Tokens::random(48)),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($roleIds as $roleId) {
                $this->db->insert('user_roles', ['user_id' => $id, 'role_id' => $roleId]);
            }

            return $id;
        });
        $this->sendInvitation($id, $email, (string) $data['name']);
        $this->audit->record($this->userId($request), 'user.created', 'user', $id, ['roles' => $data['roles']], $request);

        return $this->ok(['id' => $id], 201);
    }

    public function update(Request $request): Response
    {
        $id = $request->intParam('id');
        $actor = $this->userId($request);
        $user = $this->db->first('SELECT id, email, name, status FROM users WHERE id = ?', [$id]) ?? throw HttpException::notFound();
        $data = Validator::validate($request->json(), [
            'name' => 'sometimes|required|string|min:2|max:190',
            'status' => 'sometimes|required|in:active,disabled',
            'roles' => 'sometimes|required|array',
        ]);
        if ($id === $actor && (isset($data['status']) || isset($data['roles']))) {
            throw HttpException::forbidden('You cannot change your own status or roles.');
        }
        $targetIsSuper = $this->assertCanManage($id, $actor);

        $this->db->transaction(function () use ($id, $data, $actor, $targetIsSuper) {
            if (isset($data['name'])) {
                $this->db->update('users', ['name' => $data['name'], 'updated_at' => $this->clock->nowString()], ['id' => $id]);
            }
            if (isset($data['status'])) {
                if ($data['status'] === 'disabled' && $targetIsSuper) {
                    $this->assertAnotherSuperAdmin($id);
                }
                $this->db->update('users', ['status' => $data['status'], 'updated_at' => $this->clock->nowString()], ['id' => $id]);
            }
            if (isset($data['roles'])) {
                $roleIds = $this->resolveRoles($data['roles'], $actor);
                if ($targetIsSuper && !in_array('super-admin', $data['roles'], true)) {
                    $this->assertAnotherSuperAdmin($id);
                }
                $this->db->delete('user_roles', ['user_id' => $id]);
                foreach ($roleIds as $roleId) {
                    $this->db->insert('user_roles', ['user_id' => $id, 'role_id' => $roleId]);
                }
            }
        });

        if (isset($data['status']) || isset($data['roles'])) {
            $this->sessions->revokeAllForUser($id);
        }
        $this->audit->record($actor, 'user.updated', 'user', $id, ['fields' => array_keys($data), 'roles' => $data['roles'] ?? null], $request);

        return Response::noContent();
    }

    public function resendInvitation(Request $request): Response
    {
        $user = $this->db->first('SELECT id, email, name FROM users WHERE id = ? AND last_login_at IS NULL', [$request->intParam('id')])
            ?? throw HttpException::conflict('already_active', 'This administrator has already signed in. Ask them to use “Forgot password”.');
        $this->assertCanManage((int) $user['id'], $this->userId($request));
        $this->sendInvitation((int) $user['id'], (string) $user['email'], (string) $user['name']);
        $this->audit->record($this->userId($request), 'user.invitation_resent', 'user', $user['id'], [], $request);

        return Response::noContent();
    }

    public function revokeSessions(Request $request): Response
    {
        $id = $request->intParam('id');
        $this->db->first('SELECT id FROM users WHERE id = ?', [$id]) ?? throw HttpException::notFound();
        $this->assertCanManage($id, $this->userId($request));
        $this->sessions->revokeAllForUser($id);
        $this->audit->record($this->userId($request), 'user.sessions_revoked', 'user', $id, [], $request);

        return Response::noContent();
    }

    public function resetTwoFactor(Request $request): Response
    {
        $id = $request->intParam('id');
        if ($id === $this->userId($request)) {
            throw HttpException::forbidden('Use your own security settings to change two-factor authentication.');
        }
        $this->db->first('SELECT id FROM users WHERE id = ?', [$id]) ?? throw HttpException::notFound();
        $this->assertCanManage($id, $this->userId($request));
        $this->db->update('users', ['totp_enabled' => 0, 'totp_secret_enc' => null, 'updated_at' => $this->clock->nowString()], ['id' => $id]);
        $this->sessions->revokeAllForUser($id);
        $this->audit->record($this->userId($request), 'user.two_factor_reset', 'user', $id, [], $request);

        return Response::noContent();
    }

    /** @param list<mixed> $slugs @return list<int> */
    private function resolveRoles(array $slugs, int $actorId): array
    {
        $slugs = array_values(array_unique(array_filter($slugs, 'is_string')));
        if ($slugs === []) {
            throw HttpException::validation(['roles' => ['Assign at least one role.']]);
        }
        if (in_array('super-admin', $slugs, true) && !in_array('super-admin', $this->rbac->rolesFor($actorId), true)) {
            throw HttpException::forbidden('Only a Super Admin can grant the Super Admin role.');
        }
        $placeholders = implode(',', array_fill(0, count($slugs), '?'));
        $rows = $this->db->all("SELECT id, slug FROM roles WHERE slug IN ({$placeholders})", $slugs);
        if (count($rows) !== count($slugs)) {
            throw HttpException::validation(['roles' => ['One or more roles do not exist.']]);
        }

        return array_map('intval', array_column($rows, 'id'));
    }

    /** Only Super Admins may act on Super Admin accounts. Returns whether the target is a Super Admin. */
    private function assertCanManage(int $targetId, int $actorId): bool
    {
        $targetIsSuper = in_array('super-admin', $this->rbac->rolesFor($targetId), true);
        if ($targetIsSuper && !in_array('super-admin', $this->rbac->rolesFor($actorId), true)) {
            throw HttpException::forbidden('Only a Super Admin can manage another Super Admin.');
        }

        return $targetIsSuper;
    }

    private function assertAnotherSuperAdmin(int $exceptUserId): void
    {
        $others = (int) $this->db->value(
            "SELECT COUNT(*) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
             WHERE r.slug = 'super-admin' AND u.status = 'active' AND u.id <> ?",
            [$exceptUserId],
        );
        if ($others === 0) {
            throw HttpException::conflict('last_super_admin', 'At least one active Super Admin must remain.');
        }
    }

    private function sendInvitation(int $userId, string $email, string $name): void
    {
        $token = Tokens::random(32);
        $now = $this->clock->now();
        $this->db->insert('password_resets', [
            'user_id' => $userId,
            'token_hash' => Tokens::hash($token),
            'expires_at' => $now->modify('+72 hours')->format('Y-m-d H:i:s'),
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);
        $this->notifications->queue('admin_invitation', $email, [
            'name' => $name,
            'setup_url' => $this->config->get('app.admin_url') . '/reset-password?token=' . rawurlencode($token) . '&welcome=1',
        ], 'user', $userId);
    }
}

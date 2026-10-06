<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Database;

final class Rbac
{
    /** Canonical permission catalogue (seeded by migration, kept here for reference and tests). */
    public const PERMISSIONS = [
        'dashboard.view' => 'View the dashboard',
        'applications.view' => 'View application list and status',
        'applications.view_confidential' => 'Decrypt and read confidential application details',
        'applications.manage' => 'Approve, reject, request information, invite',
        'appointments.view' => 'View appointments',
        'appointments.manage' => 'Create, reschedule, cancel and complete appointments',
        'appointments.configure' => 'Configure appointment types, pricing and availability',
        'content.manage' => 'Manage pages, services, journal, audio, FAQs and testimonials',
        'media.manage' => 'Manage the media library',
        'events.manage' => 'Manage events and registrations',
        'seo.manage' => 'Manage SEO metadata',
        'payments.view' => 'View payments and transactions',
        'payments.refund' => 'Issue refunds',
        'reports.view' => 'View business reports',
        'settings.manage' => 'Manage settings and email templates',
        'integrations.manage' => 'Connect and manage integrations',
        'users.manage' => 'Manage administrators and roles',
        'audit.view' => 'View the audit log',
    ];

    /** Default role → permission mapping. */
    public const ROLES = [
        'super-admin' => ['name' => 'Super Admin', 'permissions' => ['*']],
        'administrator' => ['name' => 'Administrator', 'permissions' => [
            'dashboard.view', 'applications.view', 'applications.view_confidential', 'applications.manage',
            'appointments.view', 'appointments.manage', 'appointments.configure', 'content.manage', 'media.manage',
            'events.manage', 'seo.manage', 'payments.view', 'reports.view', 'settings.manage', 'integrations.manage',
        ]],
        'appointment-manager' => ['name' => 'Appointment Manager', 'permissions' => [
            'dashboard.view', 'applications.view', 'applications.view_confidential', 'applications.manage',
            'appointments.view', 'appointments.manage', 'appointments.configure', 'events.manage',
        ]],
        'content-editor' => ['name' => 'Content Editor', 'permissions' => [
            'dashboard.view', 'content.manage', 'media.manage', 'seo.manage', 'events.manage',
        ]],
        'finance-manager' => ['name' => 'Finance Manager', 'permissions' => [
            'dashboard.view', 'appointments.view', 'payments.view', 'payments.refund', 'reports.view',
        ]],
    ];

    /** @var array<int, list<string>> */
    private array $cache = [];

    public function __construct(private Database $db)
    {
    }

    /** @return list<string> */
    public function permissionsFor(int $userId): array
    {
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }
        $isSuper = (bool) $this->db->value(
            "SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.slug = 'super-admin'",
            [$userId],
        );
        if ($isSuper) {
            return $this->cache[$userId] = array_keys(self::PERMISSIONS);
        }
        $rows = $this->db->all(
            'SELECT DISTINCT p.slug FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = ?',
            [$userId],
        );

        return $this->cache[$userId] = array_column($rows, 'slug');
    }

    /** @return list<string> */
    public function rolesFor(int $userId): array
    {
        return array_column($this->db->all(
            'SELECT r.slug FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?',
            [$userId],
        ), 'slug');
    }

    public function can(int $userId, string $permission): bool
    {
        return in_array($permission, $this->permissionsFor($userId), true);
    }
}

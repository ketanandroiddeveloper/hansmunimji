<?php

declare(strict_types=1);

use App\Security\Rbac;

$quote = static fn (string $v): string => "'" . str_replace("'", "''", $v) . "'";

$up = [];
foreach (Rbac::PERMISSIONS as $slug => $description) {
    $up[] = sprintf('INSERT INTO permissions (slug, description) VALUES (%s, %s)', $quote($slug), $quote($description));
}
foreach (Rbac::ROLES as $slug => $role) {
    $up[] = sprintf('INSERT INTO roles (slug, name, is_system) VALUES (%s, %s, 1)', $quote($slug), $quote($role['name']));
    if ($role['permissions'] === ['*']) {
        continue; // Super Admin is resolved to every permission at runtime.
    }
    foreach ($role['permissions'] as $permission) {
        $up[] = sprintf(
            'INSERT INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r, permissions p WHERE r.slug = %s AND p.slug = %s',
            $quote($slug),
            $quote($permission),
        );
    }
}

return [
    'up' => $up,
    'down' => ['DELETE FROM role_permissions', 'DELETE FROM roles WHERE is_system = 1', 'DELETE FROM permissions'],
];

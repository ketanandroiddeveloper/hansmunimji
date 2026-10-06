<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Clock;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class AuditLogsController extends Controller
{
    public function __construct(private Database $db)
    {
    }

    public function index(Request $request): Response
    {
        [$page, $perPage] = $this->pagination($request, 50);
        $where = ['1 = 1'];
        $params = [];
        if ($action = $request->query('action')) {
            $where[] = 'l.action LIKE ?';
            $params[] = addcslashes((string) $action, '%_\\') . '%';
        }
        if ($entity = $request->query('entity_type')) {
            $where[] = 'l.entity_type = ?';
            $params[] = (string) $entity;
        }
        if ($user = $request->query('user_id')) {
            $where[] = 'l.user_id = ?';
            $params[] = (int) $user;
        }
        $sql = ' FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->value('SELECT COUNT(*)' . $sql, $params);
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->all("SELECT l.id, l.user_id, u.name AS user_name, l.action, l.entity_type, l.entity_id, l.metadata, l.created_at{$sql} ORDER BY l.id DESC LIMIT {$perPage} OFFSET {$offset}", $params);

        return $this->ok(array_map(static function (array $r) {
            $r['metadata'] = json_decode((string) $r['metadata'], true);
            $r['created_at'] = Clock::iso($r['created_at']);

            return $r;
        }, $rows), 200, $this->meta($page, $perPage, $total));
    }
}

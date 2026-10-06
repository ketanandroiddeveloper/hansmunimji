<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Clock;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Integrations\Google\GoogleCalendarClient;
use App\Security\Crypto;
use App\Security\Rbac;

final class DashboardController extends Controller
{
    public function __construct(private Database $db, private Clock $clock, private Rbac $rbac, private Crypto $crypto, private GoogleCalendarClient $google)
    {
    }

    public function index(Request $request): Response
    {
        $userId = $this->userId($request);
        $now = $this->clock->now();
        $nowStr = $now->format('Y-m-d H:i:s');
        $out = [];

        if ($this->rbac->can($userId, 'appointments.view')) {
            $upcoming = $this->db->all(
                "SELECT a.id, a.reference, a.status, a.starts_at, a.format, a.client_name_enc, t.title AS type_title
                 FROM appointments a JOIN appointment_types t ON t.id = a.appointment_type_id
                 WHERE a.status IN ('confirmed','rescheduled') AND a.starts_at >= ? ORDER BY a.starts_at LIMIT 8",
                [$nowStr],
            );
            $out['upcoming'] = array_map(fn ($r) => [
                'id' => (int) $r['id'],
                'reference' => $r['reference'],
                'status' => $r['status'],
                'starts_at' => Clock::iso($r['starts_at']),
                'format' => $r['format'],
                'type_title' => $r['type_title'],
                'client_name' => $this->crypto->decrypt($r['client_name_enc'], 'appointments.client_name'),
            ], $upcoming);
            $out['counts']['today'] = (int) $this->db->value(
                "SELECT COUNT(*) FROM appointments WHERE status IN ('confirmed','rescheduled') AND starts_at >= ? AND starts_at < ?",
                [$now->setTime(0, 0)->format('Y-m-d H:i:s'), $now->setTime(0, 0)->modify('+1 day')->format('Y-m-d H:i:s')],
            );
            $out['counts']['needs_attention'] = (int) $this->db->value("SELECT COUNT(*) FROM appointments WHERE status = 'payment_verification' OR calendar_sync_status = 'failed'")
                + (int) $this->db->value("SELECT COUNT(*) FROM payments WHERE status = 'reconciliation_required'");
            $out['counts']['pending_payment'] = (int) $this->db->value("SELECT COUNT(*) FROM appointments WHERE status = 'pending_payment'");
        }

        if ($this->rbac->can($userId, 'applications.view')) {
            $out['counts']['applications_new'] = (int) $this->db->value("SELECT COUNT(*) FROM applications WHERE status = 'submitted'");
            $out['counts']['applications_in_review'] = (int) $this->db->value("SELECT COUNT(*) FROM applications WHERE status IN ('under_review','info_requested')");
        }

        if ($this->rbac->can($userId, 'payments.view')) {
            $out['revenue_30d'] = $this->db->all(
                "SELECT currency, SUM(amount_minor - refunded_minor) AS net_minor, COUNT(*) AS count
                 FROM payments WHERE status IN ('captured','partially_refunded') AND captured_at >= ? GROUP BY currency",
                [$now->modify('-30 days')->format('Y-m-d H:i:s')],
            );
        }

        if ($this->rbac->can($userId, 'integrations.manage')) {
            $out['health'] = [
                'google_calendar' => $this->google->isConnected() ? 'connected' : ($this->google->isConfigured() ? 'not_connected' : 'not_configured'),
                'failed_jobs' => (int) $this->db->value("SELECT COUNT(*) FROM jobs WHERE status = 'failed' AND finished_at >= ?", [$now->modify('-7 days')->format('Y-m-d H:i:s')]),
                'failed_emails' => (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE status = 'failed' AND created_at >= ?", [$now->modify('-7 days')->format('Y-m-d H:i:s')]),
                'failed_webhooks' => (int) $this->db->value("SELECT COUNT(*) FROM webhook_events WHERE status = 'failed'"),
            ];
        }

        return $this->ok($out);
    }
}

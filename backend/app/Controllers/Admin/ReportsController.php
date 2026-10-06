<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/** Aggregate reporting only — no personal data leaves these queries. */
final class ReportsController extends Controller
{
    public function __construct(private Database $db, private Clock $clock, private Config $config)
    {
    }

    public function index(Request $request): Response
    {
        $q = Validator::validate($request->allQuery(), ['from' => 'nullable|date', 'to' => 'nullable|date']);
        $to = isset($q['to']) ? Clock::utc($q['to'] . ' 00:00:00')->modify('+1 day') : $this->clock->now();
        $from = isset($q['from']) ? Clock::utc($q['from'] . ' 00:00:00') : $to->modify('-12 months');
        $range = [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];
        $env = $this->config->environment();
        $paid = "status IN ('captured','partially_refunded','refunded') AND environment = ? AND captured_at >= ? AND captured_at < ?";

        return $this->ok([
            'range' => ['from' => Clock::iso($range[0]), 'to' => Clock::iso($range[1])],
            'environment' => $env,
            'revenue_by_month' => $this->db->all(
                "SELECT DATE_FORMAT(captured_at, '%Y-%m') AS month, currency, SUM(amount_minor) AS gross_minor, SUM(refunded_minor) AS refunded_minor, COUNT(*) AS payments
                 FROM payments WHERE {$paid}
                 GROUP BY month, currency ORDER BY month, currency",
                [$env, ...$range],
            ),
            'revenue_by_gateway' => $this->db->all(
                "SELECT gateway, currency, SUM(amount_minor) AS gross_minor, SUM(refunded_minor) AS refunded_minor, COUNT(*) AS payments
                 FROM payments WHERE {$paid}
                 GROUP BY gateway, currency ORDER BY gateway, currency",
                [$env, ...$range],
            ),
            'payment_outcomes' => $this->db->all(
                'SELECT gateway, status, COUNT(*) AS count FROM payments WHERE environment = ? AND created_at >= ? AND created_at < ? GROUP BY gateway, status ORDER BY gateway, count DESC',
                [$env, ...$range],
            ),
            'bookings_by_status' => $this->db->all(
                'SELECT status, COUNT(*) AS count FROM appointments WHERE created_at >= ? AND created_at < ? GROUP BY status ORDER BY count DESC',
                $range,
            ),
            'bookings_by_type' => $this->db->all(
                "SELECT t.title, COUNT(*) AS count FROM appointments a JOIN appointment_types t ON t.id = a.appointment_type_id
                 WHERE a.status IN ('confirmed','rescheduled','completed') AND a.starts_at >= ? AND a.starts_at < ? GROUP BY t.title ORDER BY count DESC",
                $range,
            ),
            'bookings_by_format' => $this->db->all(
                "SELECT format, COUNT(*) AS count FROM appointments WHERE status IN ('confirmed','rescheduled','completed') AND starts_at >= ? AND starts_at < ? GROUP BY format",
                $range,
            ),
            'applications_by_status' => $this->db->all(
                "SELECT status, COUNT(*) AS count FROM applications WHERE status <> 'draft' AND submitted_at >= ? AND submitted_at < ? GROUP BY status",
                $range,
            ),
            'applications_by_source' => $this->db->all(
                "SELECT COALESCE(referral_source, 'unspecified') AS source, COUNT(*) AS count FROM applications WHERE status <> 'draft' AND submitted_at >= ? AND submitted_at < ? GROUP BY source ORDER BY count DESC",
                $range,
            ),
            'event_registrations' => $this->db->all(
                "SELECT e.id, e.title, e.starts_at, e.status, e.seat_quota,
                        SUM(CASE WHEN r.status = 'confirmed' THEN r.seats ELSE 0 END) AS confirmed_seats,
                        SUM(CASE WHEN r.status = 'waitlisted' THEN r.seats ELSE 0 END) AS waitlisted_seats,
                        SUM(CASE WHEN r.status IN ('cancelled','refunded') THEN 1 ELSE 0 END) AS cancellations
                 FROM events e LEFT JOIN event_registrations r ON r.event_id = e.id
                 WHERE e.starts_at >= ? AND e.starts_at < ? GROUP BY e.id ORDER BY e.starts_at",
                $range,
            ),
            'event_revenue' => $this->db->all(
                "SELECT r.event_id, p.currency, SUM(p.amount_minor) AS gross_minor, SUM(p.refunded_minor) AS refunded_minor
                 FROM payments p JOIN event_registrations r ON p.payable_type = 'event_registration' AND p.payable_id = r.id
                 JOIN events e ON e.id = r.event_id
                 WHERE p.status IN ('captured','partially_refunded','refunded') AND p.environment = ? AND e.starts_at >= ? AND e.starts_at < ?
                 GROUP BY r.event_id, p.currency",
                [$env, ...$range],
            ),
        ]);
    }
}

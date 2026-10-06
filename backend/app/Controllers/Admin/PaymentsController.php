<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Clock;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Payments\PaymentService;
use App\Services\StatusHistory;

final class PaymentsController extends Controller
{
    public function __construct(private Database $db, private PaymentService $payments, private StatusHistory $history)
    {
    }

    public function index(Request $request): Response
    {
        [$page, $perPage] = $this->pagination($request, 25);
        $where = ['1 = 1'];
        $params = [];
        foreach (['status', 'gateway', 'currency', 'payable_type', 'environment'] as $f) {
            if ($v = $request->query($f)) {
                $where[] = "p.{$f} = ?";
                $params[] = (string) $v;
            }
        }
        if ($q = trim((string) $request->query('q', ''))) {
            $where[] = '(p.reference = ? OR p.gateway_order_id = ? OR p.gateway_payment_id = ? OR p.receipt_number = ? OR a.reference = ? OR r.reference = ?)';
            array_push($params, strtoupper($q), $q, $q, $q, strtoupper($q), strtoupper($q));
        }
        $sql = " FROM payments p
                 LEFT JOIN appointments a ON p.payable_type = 'appointment' AND a.id = p.payable_id
                 LEFT JOIN event_registrations r ON p.payable_type = 'event_registration' AND r.id = p.payable_id
                 WHERE " . implode(' AND ', $where);
        $total = (int) $this->db->value('SELECT COUNT(*)' . $sql, $params);
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->all("SELECT p.id, p.reference, p.payable_type, p.gateway, p.environment, p.gateway_order_id, p.gateway_payment_id, p.currency,
                p.amount_minor, p.refunded_minor, p.status, p.receipt_number, p.failure_reason, p.reconciliation_note, p.captured_at, p.created_at,
                COALESCE(a.reference, r.reference) AS payable_reference{$sql} ORDER BY p.id DESC LIMIT {$perPage} OFFSET {$offset}", $params);

        return $this->ok(array_map(static function (array $r) {
            $r['captured_at'] = Clock::iso($r['captured_at']);
            $r['created_at'] = Clock::iso($r['created_at']);

            return $r;
        }, $rows), 200, $this->meta($page, $perPage, $total));
    }

    public function show(Request $request): Response
    {
        $payment = $this->db->first('SELECT * FROM payments WHERE id = ?', [$request->intParam('id')]) ?? throw HttpException::notFound();
        $payment['metadata'] = json_decode((string) $payment['metadata'], true);
        $payment['refunds'] = $this->db->all('SELECT id, gateway_refund_id, amount_minor, currency, status, reason, created_at FROM refunds WHERE payment_id = ? ORDER BY id DESC', [$payment['id']]);
        $payment['payable_reference'] = $this->db->value(
            $payment['payable_type'] === 'appointment' ? 'SELECT reference FROM appointments WHERE id = ?' : 'SELECT reference FROM event_registrations WHERE id = ?',
            [$payment['payable_id']],
        );
        $payment['history'] = $this->history->for('payment', (int) $payment['id']);
        foreach ($payment['refunds'] as &$refund) {
            $refund['history'] = $this->history->for('refund', (int) $refund['id']);
        }
        unset($refund, $payment['idempotency_key']);

        return $this->ok($payment);
    }

    /**
     * Accepts a payment parked in `reconciliation_required` after the operator has checked it in the
     * gateway dashboard, confirming the booking if its slot or seat is still available.
     */
    public function acceptReconciliation(Request $request): Response
    {
        Validator::validate($request->json(), ['confirm' => 'accepted']);

        return $this->ok($this->payments->acceptReconciled($request->intParam('id'), $this->userId($request)));
    }

    public function refund(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['amount_minor' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:255']);
        $key = (string) $request->header('idempotency-key', '');
        if (!preg_match('/^[A-Za-z0-9-]{16,64}$/', $key)) {
            throw HttpException::validation(['idempotency_key' => ['An Idempotency-Key header is required for refunds.']]);
        }

        return $this->ok($this->payments->refund($request->intParam('id'), (int) $data['amount_minor'], (string) $data['reason'], $this->userId($request), 'admin:' . $key), 201);
    }

    public function exportCsv(Request $request): Response
    {
        $from = Validator::parseDateTime((string) $request->query('from', '1970-01-01T00:00:00Z'))?->format('Y-m-d H:i:s') ?? '1970-01-01 00:00:00';
        $to = Validator::parseDateTime((string) $request->query('to', '2999-01-01T00:00:00Z'))?->format('Y-m-d H:i:s') ?? '2999-01-01 00:00:00';
        $rows = $this->db->all(
            "SELECT p.id, p.receipt_number, p.payable_type, COALESCE(a.reference, r.reference) AS reference, p.gateway, p.gateway_payment_id,
                    p.currency, p.amount_minor, p.refunded_minor, p.status, p.captured_at, p.created_at
             FROM payments p
             LEFT JOIN appointments a ON p.payable_type = 'appointment' AND a.id = p.payable_id
             LEFT JOIN event_registrations r ON p.payable_type = 'event_registration' AND r.id = p.payable_id
             WHERE p.created_at >= ? AND p.created_at < ? ORDER BY p.id",
            [$from, $to],
        );
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['id', 'receipt', 'type', 'reference', 'gateway', 'gateway_payment_id', 'currency', 'amount', 'refunded', 'status', 'captured_at_utc', 'created_at_utc'], escape: '');
        foreach ($rows as $r) {
            $r['amount_minor'] = number_format((int) $r['amount_minor'] / 100, 2, '.', '');
            $r['refunded_minor'] = number_format((int) $r['refunded_minor'] / 100, 2, '.', '');
            // Neutralise spreadsheet formula injection.
            fputcsv($out, array_map(static fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, array_values($r)), escape: '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="payments-' . gmdate('Ymd') . '.csv"',
        ]);
    }
}

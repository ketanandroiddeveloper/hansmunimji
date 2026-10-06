<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Security\BlindIndex;
use App\Security\Crypto;
use App\Security\Tokens;
use App\Services\Notifications\NotificationService;

/** Data subject requests (access / deletion). Requests are email-verified, then fulfilled by an administrator. */
final class PrivacyController extends Controller
{
    public function __construct(
        private Database $db,
        private Clock $clock,
        private Config $config,
        private Crypto $crypto,
        private BlindIndex $blindIndex,
        private NotificationService $notifications,
    ) {
    }

    public function store(Request $request): Response
    {
        $data = Validator::validate($request->json(), ['email' => 'required|email', 'type' => 'required|in:access,deletion']);
        $token = Tokens::random(32);
        $id = $this->db->insert('data_requests', [
            'type' => $data['type'],
            'email_bidx' => $this->blindIndex->email((string) $data['email']),
            'email_enc' => $this->crypto->encrypt((string) $data['email'], 'data_requests.email'),
            'verify_token_hash' => Tokens::hash($token),
            'created_at' => $this->clock->nowString(),
        ]);
        $this->notifications->queue('privacy_request_verification', (string) $data['email'], [
            'request_type' => $data['type'] === 'access' ? 'access to your data' : 'deletion of your data',
            'verify_url' => $this->config->get('app.url') . '/api/v1/privacy/requests/verify/' . $token,
        ], 'data_request', $id);

        // Identical response regardless of whether we hold any data for this address.
        return $this->ok(['received' => true], 202);
    }

    public function verify(Request $request): Response
    {
        $row = $this->db->first("SELECT id FROM data_requests WHERE verify_token_hash = ? AND status = 'pending_verification'", [Tokens::hash($request->param('token'))]);
        if ($row !== null) {
            $this->db->update('data_requests', ['status' => 'verified', 'verified_at' => $this->clock->nowString()], ['id' => $row['id']]);
            $this->notifications->queueAdmin('admin_new_application', ['reference' => 'DSR-' . $row['id'], 'consultation_type' => 'data request', 'kind' => 'Verified data subject request']);
        }

        return Response::redirect($this->config->get('app.frontend_url') . '/privacy/requests?verified=1');
    }
}

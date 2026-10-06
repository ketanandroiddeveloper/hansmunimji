<?php

declare(strict_types=1);

namespace App\Services\Applications;

use App\Core\Clock;
use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Validator;
use App\Security\AuditLogger;
use App\Security\BlindIndex;
use App\Security\Crypto;
use App\Security\Tokens;
use App\Services\Notifications\NotificationService;
use App\Services\SettingsService;
use App\Services\StatusHistory;

/**
 * Private access applications: encrypted drafts with save-and-resume, submission with consent
 * records, and the admin review workflow (approve / reject / request information / invite).
 */
final class ApplicationService
{
    public const ENCRYPTED = [
        'full_name', 'email', 'phone', 'city', 'professional_background', 'designation', 'organization',
        'core_objective', 'preferred_availability', 'referral_details', 'confidential_notes',
    ];

    private const PLAIN = ['country', 'consultation_type', 'preferred_format', 'referral_source'];

    /** Rules per step (used for drafts: everything optional except shape). */
    public const RULES = [
        'full_name' => 'required|string|min:2|max:190',
        'email' => 'required|email',
        'phone' => 'required|phone',
        'country' => 'required|string|min:2|max:2',
        'city' => 'required|string|max:120',
        'professional_background' => 'required|string|min:20|max:3000',
        'designation' => 'required|string|max:190',
        'organization' => 'required|string|max:190',
        'consultation_type' => 'required|slug|max:120',
        'core_objective' => 'required|string|min:30|max:4000',
        'preferred_format' => 'required|in:google_meet,phone,in_person',
        'preferred_availability' => 'nullable|string|max:500',
        'referral_source' => 'required|in:referral,search,event,media,social,other',
        'referral_details' => 'nullable|string|max:500',
        'confidential_notes' => 'nullable|string|max:4000',
    ];

    public function __construct(
        private Database $db,
        private Clock $clock,
        private Config $config,
        private Crypto $crypto,
        private BlindIndex $blindIndex,
        private NotificationService $notifications,
        private SettingsService $settings,
        private AuditLogger $audit,
        private StatusHistory $history,
    ) {
    }

    // ---------------------------------------------------------------- public

    /** @return array{reference: string, resume_token: string} */
    public function createDraft(array $input): array
    {
        $data = $this->validateDraft($input);
        $token = Tokens::random(32);
        $reference = Tokens::reference('PA');
        $now = $this->clock->now();

        $id = $this->db->insert('applications', [
            'reference' => $reference,
            'status' => 'draft',
            'current_step' => (int) ($input['current_step'] ?? 1),
            'access_token_hash' => Tokens::hash($token),
            'access_expires_at' => $now->modify('+' . (int) $this->config->get('security.access_tokens.application_days') . ' days'),
            'retention_until' => $now->modify('+30 days'),
            'created_at' => $now,
            'updated_at' => $now,
        ] + $this->columns($data));

        if (!empty($data['email'])) {
            $this->notifications->queue('application_draft_resume', (string) $data['email'], [
                'name' => (string) ($data['full_name'] ?? ''),
                'resume_url' => $this->config->get('app.frontend_url') . '/private-access?resume=' . $reference . '#access=' . $token,
            ], 'application', $id);
        }

        return ['reference' => $reference, 'resume_token' => $token];
    }

    public function updateDraft(string $reference, string $token, array $input): void
    {
        $row = $this->findWithToken($reference, $token);
        if ($row['status'] !== 'draft' && $row['status'] !== 'info_requested') {
            throw HttpException::conflict('already_submitted', 'This application has already been submitted.');
        }
        $data = $this->validateDraft($input);
        $update = $this->columns($data) + ['updated_at' => $this->clock->nowString()];
        if (isset($input['current_step'])) {
            $update['current_step'] = max(1, min(5, (int) $input['current_step']));
        }
        $this->db->update('applications', $update, ['id' => $row['id']]);
    }

    /** @return array<string, mixed> decrypted draft for the token holder */
    public function getDraft(string $reference, string $token): array
    {
        $row = $this->findWithToken($reference, $token);
        if (!in_array($row['status'], ['draft', 'info_requested'], true)) {
            throw HttpException::conflict('already_submitted', 'This application has already been submitted.');
        }

        return ['reference' => $row['reference'], 'status' => $row['status'], 'current_step' => (int) $row['current_step']]
            + $this->decrypted($row)
            + array_intersect_key($row, array_flip(self::PLAIN))
            + ['info_request' => $this->crypto->decrypt($row['info_request_enc'], 'applications.info_request')];
    }

    public function submitDraft(string $reference, string $token, array $input, Request $request): array
    {
        $row = $this->findWithToken($reference, $token);
        if (!in_array($row['status'], ['draft', 'info_requested'], true)) {
            throw HttpException::conflict('already_submitted', 'This application has already been submitted.');
        }
        $stored = array_filter($this->decrypted($row) + array_intersect_key($row, array_flip(self::PLAIN)), static fn ($v) => $v !== null);
        $merged = array_merge($stored, array_filter(array_intersect_key($input, self::RULES), static fn ($v) => $v !== null && $v !== ''));

        return $this->finalize((int) $row['id'], $merged, $input, $request, $row['status'] === 'info_requested', $token);
    }

    /** One-shot submission without a draft. */
    public function submitDirect(array $input, Request $request): array
    {
        $draft = $this->createDraftSilently();
        $result = $this->finalize($draft['id'], $input, $input, $request, false, $draft['token']);

        return $result + ['access_token' => $draft['token']];
    }

    /** @return array<string, mixed> */
    public function status(string $reference, string $token): array
    {
        $row = $this->findWithToken($reference, $token);

        return [
            'reference' => $row['reference'],
            'status' => $row['status'] === 'draft' ? 'draft' : $row['status'],
            'submitted_at' => Clock::iso($row['submitted_at']),
            'message' => match ($row['status']) {
                'submitted', 'under_review' => 'Your application is with the private office and is being reviewed in confidence.',
                'info_requested' => 'The private office has requested additional information. Please check your email.',
                'approved', 'invited' => 'Your application has been approved. An invitation to arrange your consultation has been sent to your email.',
                'converted' => 'Your consultation has been arranged. Details are in your confirmation email.',
                'rejected' => 'Thank you for your interest. The private office is unable to accommodate this request at present.',
                'archived' => 'This application is closed. Please contact the private office if you have any questions.',
                default => 'Your application has not yet been submitted.',
            },
        ];
    }

    // ---------------------------------------------------------------- admin

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function adminList(array $filters, int $page, int $perPage, bool $canViewConfidential): array
    {
        $where = ["status <> 'draft'"];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['consultation_type'])) {
            $where[] = 'consultation_type = ?';
            $params[] = (string) $filters['consultation_type'];
        }
        if (!empty($filters['q'])) {
            $q = trim((string) $filters['q']);
            if (filter_var($q, FILTER_VALIDATE_EMAIL)) {
                $where[] = 'email_bidx = ?';
                $params[] = $this->blindIndex->email($q);
            } else {
                $where[] = 'reference = ?';
                $params[] = strtoupper($q);
            }
        }
        $sql = ' FROM applications WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->value('SELECT COUNT(*)' . $sql, $params);
        $offset = ($page - 1) * $perPage;
        $rows = $this->db->all("SELECT *{$sql} ORDER BY submitted_at DESC, id DESC LIMIT {$perPage} OFFSET {$offset}", $params);

        $items = array_map(function (array $row) use ($canViewConfidential) {
            $item = [
                'id' => (int) $row['id'],
                'reference' => $row['reference'],
                'status' => $row['status'],
                'consultation_type' => $row['consultation_type'],
                'preferred_format' => $row['preferred_format'],
                'country' => $row['country'],
                'referral_source' => $row['referral_source'],
                'submitted_at' => Clock::iso($row['submitted_at']),
            ];
            if ($canViewConfidential) {
                $item['full_name'] = $this->crypto->decrypt($row['full_name_enc'], 'applications.full_name');
                $item['organization'] = $this->crypto->decrypt($row['organization_enc'], 'applications.organization');
            }

            return $item;
        }, $rows);

        return ['items' => $items, 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function adminShow(int $id, bool $canViewConfidential, int $userId, Request $request): array
    {
        $row = $this->db->first('SELECT * FROM applications WHERE id = ?', [$id]) ?? throw HttpException::notFound();
        $out = [
            'id' => (int) $row['id'],
            'reference' => $row['reference'],
            'status' => $row['status'],
            'submitted_at' => Clock::iso($row['submitted_at']),
            'reviewed_at' => Clock::iso($row['reviewed_at']),
            'invite_expires_at' => Clock::iso($row['invite_expires_at']),
            'converted_at' => Clock::iso($row['converted_at'] ?? null),
            'appointments' => $this->db->all('SELECT reference, status, starts_at FROM appointments WHERE application_id = ? ORDER BY starts_at DESC', [$id]),
            'history' => $this->history->for('application', $id),
        ] + array_intersect_key($row, array_flip(self::PLAIN));

        if ($canViewConfidential) {
            $out += $this->decrypted($row) + [
                'admin_notes' => $this->crypto->decrypt($row['admin_notes_enc'], 'applications.admin_notes'),
                'info_request' => $this->crypto->decrypt($row['info_request_enc'], 'applications.info_request'),
            ];
            $this->audit->record($userId, 'application.viewed_confidential', 'application', $id, [], $request);
        }

        if ($row['status'] === 'submitted') {
            $this->db->update('applications', ['status' => 'under_review', 'updated_at' => $this->clock->nowString()], ['id' => $id]);
            $this->history->record('application', $id, 'submitted', 'under_review', 'admin', $userId, 'Opened for review.');
            $out['status'] = 'under_review';
        }

        return $out;
    }

    /**
     * Single entry point for `PATCH /admin/applications/{id}/status`, delegating to the
     * dedicated actions so notifications and side effects stay identical.
     *
     * @param array<string, mixed> $input
     */
    public function changeStatus(int $id, array $input, int $userId, Request $request): void
    {
        $data = Validator::validate($input, [
            'status' => 'required|in:under_review,info_requested,additional_info_required,approved,invited,rejected,archived',
            'message' => 'nullable|string|max:4000',
            'appointment_type_id' => 'nullable|integer',
            'note' => 'nullable|string|max:500',
        ]);
        match ($data['status']) {
            'under_review' => $this->markUnderReview($id, $userId, $request),
            'info_requested', 'additional_info_required' => $this->requestInfo($id, (string) ($data['message'] ?? throw HttpException::validation(['message' => ['Describe the information required.']])), $userId, $request),
            'approved' => $this->approve($id, $userId, $request),
            'invited' => $this->invite($id, isset($data['appointment_type_id']) ? (int) $data['appointment_type_id'] : null, $userId, $request),
            'rejected' => $this->reject($id, $userId, $request),
            'archived' => $this->archive($id, $userId, $request, $data['note'] ?? null),
        };
    }

    public function markUnderReview(int $id, int $userId, Request $request): void
    {
        $this->transition($id, ['submitted'], 'under_review', $userId);
        $this->audit->record($userId, 'application.under_review', 'application', $id, [], $request);
    }

    /** Closes an application without notifying the applicant; retention then applies. */
    public function archive(int $id, int $userId, Request $request, ?string $note = null): void
    {
        $this->transition($id, ['submitted', 'under_review', 'info_requested', 'approved', 'invited', 'rejected', 'converted'], 'archived', $userId, $note);
        $this->db->run(
            'UPDATE applications SET retention_until = COALESCE(retention_until, ?), invite_token_hash = NULL WHERE id = ?',
            [$this->clock->now()->modify('+' . (int) $this->settings->get('privacy.retention_rejected_days', 365) . ' days')->format('Y-m-d H:i:s'), $id],
        );
        $this->audit->record($userId, 'application.archived', 'application', $id, [], $request);
    }

    public function approve(int $id, int $userId, Request $request): void
    {
        $row = $this->transition($id, ['submitted', 'under_review', 'info_requested'], 'approved', $userId);
        $this->notifyApplicant($row, 'application_approved', []);
        $this->audit->record($userId, 'application.approved', 'application', $id, [], $request);
    }

    public function reject(int $id, int $userId, Request $request): void
    {
        $row = $this->transition($id, ['submitted', 'under_review', 'info_requested', 'approved'], 'rejected', $userId);
        $this->db->update('applications', ['retention_until' => $this->clock->now()->modify('+' . (int) $this->settings->get('privacy.retention_rejected_days', 365) . ' days')], ['id' => $id]);
        $this->notifyApplicant($row, 'application_rejected', []);
        $this->audit->record($userId, 'application.rejected', 'application', $id, [], $request);
    }

    public function requestInfo(int $id, string $message, int $userId, Request $request): void
    {
        $row = $this->transition($id, ['submitted', 'under_review'], 'info_requested', $userId);
        $token = Tokens::random(32);
        $this->db->update('applications', [
            'info_request_enc' => $this->crypto->encrypt($message, 'applications.info_request'),
            'access_token_hash' => Tokens::hash($token),
            'access_expires_at' => $this->clock->now()->modify('+14 days'),
        ], ['id' => $id]);
        $this->notifyApplicant($row, 'application_info_requested', [
            'resume_url' => $this->config->get('app.frontend_url') . '/private-access?resume=' . $row['reference'] . '#access=' . $token,
        ]);
        $this->audit->record($userId, 'application.info_requested', 'application', $id, [], $request);
    }

    public function invite(int $id, ?int $appointmentTypeId, int $userId, Request $request): void
    {
        $row = $this->db->first('SELECT * FROM applications WHERE id = ?', [$id]) ?? throw HttpException::notFound();
        if (!in_array($row['status'], ['approved', 'invited'], true)) {
            throw HttpException::conflict('invalid_state', 'Approve the application before inviting the applicant to book.');
        }
        $typeSlug = null;
        if ($appointmentTypeId !== null) {
            $typeSlug = $this->db->value('SELECT slug FROM appointment_types WHERE id = ? AND is_active = 1', [$appointmentTypeId]);
            if (!$typeSlug) {
                throw HttpException::validation(['appointment_type_id' => ['Select an active consultation type.']]);
            }
        }
        $token = Tokens::random(32);
        $expires = $this->clock->now()->modify('+' . (int) $this->config->get('security.access_tokens.invite_days') . ' days');
        $this->db->update('applications', [
            'status' => 'invited',
            'invite_token_hash' => Tokens::hash($token),
            'invite_expires_at' => $expires,
            'invited_appointment_type_id' => $appointmentTypeId,
            'updated_at' => $this->clock->nowString(),
        ], ['id' => $id]);
        $this->history->record('application', $id, (string) $row['status'], 'invited', 'admin', $userId, $row['status'] === 'invited' ? 'Invitation re-issued.' : null);

        $url = $this->config->get('app.frontend_url') . '/consultation' . ($typeSlug ? '?type=' . $typeSlug : '') . '#invite=' . $token;
        $this->notifyApplicant($row, 'application_invited', ['booking_url' => $url, 'expires_on' => $expires->format('j F Y')]);
        $this->audit->record($userId, 'application.invited', 'application', $id, ['appointment_type_id' => $appointmentTypeId], $request);
    }

    public function updateNotes(int $id, ?string $notes, int $userId): void
    {
        $this->db->update('applications', ['admin_notes_enc' => $this->crypto->encrypt($notes, 'applications.admin_notes'), 'updated_at' => $this->clock->nowString()], ['id' => $id]);
        $this->audit->record($userId, 'application.notes_updated', 'application', $id);
    }

    /** Scheduler: purge expired drafts and rejected/archived applications past retention. */
    public function applyRetention(): int
    {
        return $this->db->run(
            "DELETE FROM applications WHERE retention_until IS NOT NULL AND retention_until < ? AND status IN ('draft','rejected','archived')
               AND id NOT IN (SELECT application_id FROM appointments WHERE application_id IS NOT NULL)",
            [$this->clock->nowString()],
        )->rowCount();
    }

    // ---------------------------------------------------------------- internals

    private function finalize(int $id, array $data, array $rawInput, Request $request, bool $isInfoResponse, string $token): array
    {
        $validated = Validator::validate($data, self::RULES);
        Validator::validate($rawInput, ['consent_privacy' => 'accepted', 'consent_communications' => 'nullable|boolean']);
        $validated['country'] = strtoupper((string) $validated['country']);

        $now = $this->clock->now();
        $this->db->transaction(function () use ($id, $validated, $rawInput, $request, $now) {
            $this->db->update('applications', $this->columns($validated) + [
                'status' => 'submitted',
                'current_step' => 5,
                'submitted_at' => $now,
                'retention_until' => null,
                'updated_at' => $now,
            ], ['id' => $id]);

            foreach (['privacy' => true, 'communications' => !empty($rawInput['consent_communications'])] as $type => $granted) {
                $this->db->insert('consent_records', [
                    'subject_type' => 'application',
                    'subject_id' => $id,
                    'consent_type' => $type,
                    'policy_version' => (string) $this->settings->get('privacy.policy_version', '1.0'),
                    'granted' => $granted ? 1 : 0,
                    'ip_hash' => hash_hmac('sha256', $request->ip($this->config->get('app.trusted_proxies', [])), (string) $this->config->get('security.app_key')),
                    'user_agent_hash' => hash('sha256', $request->userAgent()),
                    'created_at' => $now,
                ]);
            }
        });

        $this->history->record('application', $id, $isInfoResponse ? 'info_requested' : 'draft', 'submitted', 'client', null, $isInfoResponse ? 'Additional information provided.' : null);
        $reference = (string) $this->db->value('SELECT reference FROM applications WHERE id = ?', [$id]);
        $this->notifications->cancelFor('application', $id, 'application_draft_resume');
        $this->notifications->queue('application_received', (string) $validated['email'], [
            'name' => (string) $validated['full_name'],
            'reference' => $reference,
            'status_url' => $this->config->get('app.frontend_url') . '/private-access/status/' . $reference . '#access=' . $token,
        ], 'application', $id);
        // Admin notification deliberately excludes personal or confidential content.
        $this->notifications->queueAdmin('admin_new_application', [
            'reference' => $reference,
            'consultation_type' => (string) $validated['consultation_type'],
            'kind' => $isInfoResponse ? 'Additional information received' : 'New private access application',
        ], 'application', $id);

        return ['reference' => $reference, 'status' => 'submitted'];
    }

    /** @return array{id: int, token: string} */
    private function createDraftSilently(): array
    {
        $token = Tokens::random(32);
        $now = $this->clock->now();
        $id = $this->db->insert('applications', [
            'reference' => Tokens::reference('PA'),
            'status' => 'draft',
            'access_token_hash' => Tokens::hash($token),
            'access_expires_at' => $now->modify('+' . (int) $this->config->get('security.access_tokens.application_days') . ' days'),
            'retention_until' => $now->modify('+1 day'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['id' => $id, 'token' => $token];
    }

    /** @return array<string, mixed> */
    private function validateDraft(array $input): array
    {
        $rules = [];
        foreach (self::RULES as $field => $rule) {
            $rules[$field] = 'sometimes|nullable|' . str_replace(['required|', 'nullable|'], '', $rule);
        }

        return Validator::validate($input, $rules);
    }

    /** @return array<string, mixed> */
    private function columns(array $data): array
    {
        $columns = $this->crypto->encryptFields($data, self::ENCRYPTED, 'applications');
        if (array_key_exists('email', $data)) {
            $columns['email_bidx'] = $this->blindIndex->email($data['email'] === null ? null : (string) $data['email']);
        }
        foreach (self::PLAIN as $field) {
            if (array_key_exists($field, $data)) {
                $columns[$field] = $field === 'country' && $data[$field] !== null ? strtoupper((string) $data[$field]) : $data[$field];
            }
        }

        return $columns;
    }

    /** @return array<string, string|null> */
    private function decrypted(array $row): array
    {
        return $this->crypto->decryptFields($row, self::ENCRYPTED, 'applications');
    }

    /** @return array<string, mixed> */
    private function findWithToken(string $reference, string $token): array
    {
        $row = $this->db->first('SELECT * FROM applications WHERE reference = ?', [strtoupper($reference)]);
        if ($row === null || $token === '' || !Tokens::equals((string) $row['access_token_hash'], $token)) {
            throw HttpException::notFound('Application not found.');
        }
        if (Clock::utc((string) $row['access_expires_at']) < $this->clock->now()) {
            throw new HttpException(410, 'link_expired', 'This link has expired. Please contact the private office.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function transition(int $id, array $from, string $to, int $userId, ?string $note = null): array
    {
        $row = $this->db->first('SELECT * FROM applications WHERE id = ?', [$id]) ?? throw HttpException::notFound();
        if (!in_array($row['status'], $from, true)) {
            throw HttpException::conflict('invalid_state', "This application cannot be moved to '{$to}' from '{$row['status']}'.");
        }
        $this->db->update('applications', ['status' => $to, 'reviewed_by' => $userId, 'reviewed_at' => $this->clock->nowString(), 'updated_at' => $this->clock->nowString()], ['id' => $id]);
        $this->history->record('application', $id, (string) $row['status'], $to, 'admin', $userId, $note);

        return $row;
    }

    /** @param array<string, string> $extra */
    private function notifyApplicant(array $row, string $template, array $extra): void
    {
        $email = $this->crypto->decrypt($row['email_enc'], 'applications.email');
        if ($email === null) {
            return;
        }
        $this->notifications->queue($template, $email, [
            'name' => (string) $this->crypto->decrypt($row['full_name_enc'], 'applications.full_name'),
            'reference' => (string) $row['reference'],
        ] + $extra, 'application', (int) $row['id']);
    }
}

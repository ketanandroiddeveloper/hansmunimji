<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Clock;
use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;

/**
 * Append-only audit trail of security-relevant actions. Metadata is redacted with the same
 * rules as application logs and must never contain confidential content.
 */
final class AuditLogger
{
    public function __construct(private Database $db, private Clock $clock, private string $hmacKey)
    {
    }

    /** @param array<string, mixed> $metadata */
    public function record(
        ?int $userId,
        string $action,
        ?string $entityType = null,
        int|string|null $entityId = null,
        array $metadata = [],
        ?Request $request = null,
    ): void {
        $this->db->insert('audit_logs', [
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'ip_hash' => $request ? hash_hmac('sha256', $request->ip(), $this->hmacKey) : null,
            'user_agent' => $request?->userAgent(),
            'metadata' => Logger::redact($metadata),
            'created_at' => $this->clock->nowString(),
        ]);
    }
}

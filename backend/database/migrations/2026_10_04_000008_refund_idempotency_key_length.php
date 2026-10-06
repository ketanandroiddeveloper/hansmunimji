<?php

declare(strict_types=1);

// Refund keys are namespaced ("job:" + SHA-256 hex, "admin:" + a 16–64 character client key), so
// they can exceed 64 characters; at 64 every automatic refund failed to insert.
return [
    'up' => [
        'ALTER TABLE refunds MODIFY idempotency_key VARCHAR(100) NOT NULL',
    ],
    'down' => [
        'ALTER TABLE refunds MODIFY idempotency_key VARCHAR(64) NOT NULL',
    ],
];

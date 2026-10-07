<?php

declare(strict_types=1);

$table = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

return [
    'up' => [
        // Operational trail for third-party calls. Holds no tokens, message bodies, addresses or provider error text.
        "CREATE TABLE integration_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(20) NOT NULL,
            environment VARCHAR(20) NOT NULL,
            operation VARCHAR(60) NOT NULL,
            outcome ENUM('success','failure') NOT NULL,
            http_status SMALLINT UNSIGNED NULL,
            error_category VARCHAR(40) NULL,
            reference VARCHAR(64) NULL COMMENT 'internal booking or test reference only',
            duration_ms INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            KEY integration_logs_recent_idx (provider, environment, id),
            KEY integration_logs_operation_idx (provider, environment, operation, id)
        ) {$table}",
    ],
    'down' => [
        'DROP TABLE IF EXISTS integration_logs',
    ],
];

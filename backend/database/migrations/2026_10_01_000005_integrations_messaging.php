<?php

declare(strict_types=1);

$table = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

return [
    'up' => [
        "CREATE TABLE calendar_integrations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(20) NOT NULL,
            environment VARCHAR(20) NOT NULL,
            account_email VARCHAR(190) NULL,
            calendar_id VARCHAR(255) NOT NULL DEFAULT 'primary',
            access_token_enc TEXT NULL,
            refresh_token_enc TEXT NULL,
            token_expires_at DATETIME NULL,
            scopes VARCHAR(500) NULL,
            status ENUM('connected','needs_reauth','disconnected') NOT NULL DEFAULT 'disconnected',
            connected_by BIGINT UNSIGNED NULL,
            last_error VARCHAR(255) NULL,
            last_synced_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY calendar_provider_env_unique (provider, environment),
            CONSTRAINT calendar_user_fk FOREIGN KEY (connected_by) REFERENCES users(id) ON DELETE SET NULL
        ) {$table}",

        "CREATE TABLE email_templates (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(80) NOT NULL UNIQUE,
            name VARCHAR(190) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            body_html MEDIUMTEXT NOT NULL,
            body_text TEXT NULL,
            variables JSON NULL,
            updated_by BIGINT UNSIGNED NULL,
            updated_at DATETIME NOT NULL,
            CONSTRAINT templates_user_fk FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) {$table}",

        "CREATE TABLE notifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            channel ENUM('email') NOT NULL DEFAULT 'email',
            template_slug VARCHAR(80) NOT NULL,
            recipient_enc TEXT NOT NULL,
            payload_enc MEDIUMTEXT NULL,
            related_type VARCHAR(40) NULL,
            related_id BIGINT UNSIGNED NULL,
            status ENUM('queued','sending','sent','failed','cancelled') NOT NULL DEFAULT 'queued',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            available_at DATETIME NOT NULL,
            sent_at DATETIME NULL,
            provider_message_id VARCHAR(191) NULL,
            last_error VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            KEY notifications_due_idx (status, available_at),
            KEY notifications_related_idx (related_type, related_id)
        ) {$table}",

        "CREATE TABLE jobs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(80) NOT NULL,
            payload JSON NOT NULL,
            unique_key VARCHAR(191) NULL,
            status ENUM('pending','reserved','done','failed') NOT NULL DEFAULT 'pending',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
            available_at DATETIME NOT NULL,
            reserved_at DATETIME NULL,
            finished_at DATETIME NULL,
            last_error VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY jobs_unique_key (unique_key),
            KEY jobs_due_idx (status, available_at)
        ) {$table}",

        "CREATE TABLE data_requests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            type ENUM('access','deletion') NOT NULL,
            email_bidx CHAR(64) NOT NULL,
            email_enc TEXT NOT NULL,
            status ENUM('pending_verification','verified','completed','rejected') NOT NULL DEFAULT 'pending_verification',
            verify_token_hash CHAR(64) NOT NULL,
            verified_at DATETIME NULL,
            completed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            KEY data_requests_email_idx (email_bidx)
        ) {$table}",
    ],
    'down' => [
        'DROP TABLE IF EXISTS data_requests',
        'DROP TABLE IF EXISTS jobs',
        'DROP TABLE IF EXISTS notifications',
        'DROP TABLE IF EXISTS email_templates',
        'DROP TABLE IF EXISTS calendar_integrations',
    ],
];

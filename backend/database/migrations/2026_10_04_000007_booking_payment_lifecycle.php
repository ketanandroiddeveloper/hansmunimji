<?php

declare(strict_types=1);

$table = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

return [
    'up' => [
        // ------------------------------------------------------------ payments
        "ALTER TABLE payments
            MODIFY status ENUM('created','pending','captured','failed','refunded','partially_refunded','cancelled','reconciliation_required') NOT NULL DEFAULT 'created',
            ADD COLUMN reference VARCHAR(24) NULL AFTER id,
            ADD COLUMN reconciliation_note VARCHAR(255) NULL AFTER failure_reason",
        "UPDATE payments SET reference = CONCAT('PY-', LPAD(id, 8, '0')) WHERE reference IS NULL",
        'ALTER TABLE payments MODIFY reference VARCHAR(24) NOT NULL, ADD UNIQUE KEY payments_reference_unique (reference)',

        // ------------------------------------------------------------ appointments
        "ALTER TABLE appointments
            MODIFY status ENUM('pending_application','awaiting_approval','pending_payment','payment_verification','confirmed','rescheduled','cancelled','completed','payment_failed','refunded','expired','no_show') NOT NULL,
            MODIFY calendar_sync_status ENUM('not_required','pending','processing','synced','retry_required','failed') NOT NULL DEFAULT 'pending',
            ADD COLUMN country CHAR(2) NULL AFTER city_id,
            ADD COLUMN no_show_at DATETIME NULL AFTER completed_at,
            ADD COLUMN expired_at DATETIME NULL AFTER no_show_at",

        // ------------------------------------------------------------ applications
        "ALTER TABLE applications
            MODIFY status ENUM('draft','submitted','under_review','info_requested','approved','invited','rejected','archived','converted') NOT NULL DEFAULT 'draft',
            ADD COLUMN converted_at DATETIME NULL AFTER reviewed_at",

        // ------------------------------------------------------------ events
        "ALTER TABLE events
            ADD COLUMN registration_opens_at DATETIME NULL AFTER registration_mode,
            ADD COLUMN registration_closes_at DATETIME NULL AFTER registration_opens_at,
            ADD COLUMN waitlist_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER seat_quota,
            ADD COLUMN tax_label VARCHAR(60) NULL AFTER requirements,
            ADD COLUMN tax_rate_bp INT UNSIGNED NOT NULL DEFAULT 0 AFTER tax_label,
            ADD COLUMN tax_inclusive TINYINT(1) NOT NULL DEFAULT 1 AFTER tax_rate_bp,
            ADD COLUMN cancellation_policy TEXT NULL AFTER tax_inclusive,
            ADD COLUMN cancellation_window_hours SMALLINT UNSIGNED NOT NULL DEFAULT 168 AFTER cancellation_policy,
            ADD COLUMN refund_on_cancel_percent TINYINT UNSIGNED NOT NULL DEFAULT 100 AFTER cancellation_window_hours",
        "CREATE TABLE event_prices (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_id BIGINT UNSIGNED NOT NULL,
            currency CHAR(3) NOT NULL,
            amount_minor BIGINT UNSIGNED NOT NULL,
            UNIQUE KEY event_currency_unique (event_id, currency),
            CONSTRAINT event_prices_event_fk FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
        ) {$table}",
        'INSERT INTO event_prices (event_id, currency, amount_minor) SELECT id, currency, price_minor FROM events WHERE currency IS NOT NULL AND price_minor > 0',
        'ALTER TABLE events DROP COLUMN currency, DROP COLUMN price_minor',

        "ALTER TABLE event_registrations
            MODIFY status ENUM('pending_application','pending_payment','confirmed','cancelled','refunded','waitlisted','expired') NOT NULL,
            ADD COLUMN subtotal_minor BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER currency,
            ADD COLUMN tax_minor BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER subtotal_minor,
            ADD COLUMN country CHAR(2) NULL AFTER seats,
            ADD COLUMN confirmed_at DATETIME NULL AFTER hold_expires_at,
            ADD COLUMN cancelled_at DATETIME NULL AFTER confirmed_at",
        'UPDATE event_registrations SET subtotal_minor = amount_minor',
        "CREATE TABLE event_reminders_sent (
            registration_id BIGINT UNSIGNED NOT NULL,
            offset_minutes INT UNSIGNED NOT NULL,
            sent_at DATETIME NOT NULL,
            PRIMARY KEY (registration_id, offset_minutes),
            CONSTRAINT event_reminders_registration_fk FOREIGN KEY (registration_id) REFERENCES event_registrations(id) ON DELETE CASCADE
        ) {$table}",

        // Payment links now use the real hold expiry; untouched default wording only (edited templates are kept).
        "UPDATE email_templates SET body_html = REPLACE(body_html, 'using your secure link within three days.', 'using your secure link{{#if hold_expires_local}} by {{hold_expires_local}}{{/if}}.')
         WHERE slug = 'event_payment_request'",

        // ------------------------------------------------------------ status history
        "CREATE TABLE status_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            subject_type ENUM('appointment','payment','event_registration','application','refund') NOT NULL,
            subject_id BIGINT UNSIGNED NOT NULL,
            from_status VARCHAR(40) NULL,
            to_status VARCHAR(40) NOT NULL,
            source VARCHAR(20) NOT NULL COMMENT 'client | admin | webhook | verify | scheduler | system',
            actor_user_id BIGINT UNSIGNED NULL,
            note VARCHAR(255) NULL COMMENT 'operational context only; never personal or payment data',
            created_at DATETIME NOT NULL,
            KEY status_history_subject_idx (subject_type, subject_id, id),
            CONSTRAINT status_history_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) {$table}",
    ],
    'down' => [
        'DROP TABLE IF EXISTS status_history',
        "UPDATE email_templates SET body_html = REPLACE(body_html, 'using your secure link{{#if hold_expires_local}} by {{hold_expires_local}}{{/if}}.', 'using your secure link within three days.')
         WHERE slug = 'event_payment_request'",
        'DROP TABLE IF EXISTS event_reminders_sent',
        "ALTER TABLE event_registrations DROP COLUMN cancelled_at, DROP COLUMN confirmed_at, DROP COLUMN country, DROP COLUMN tax_minor, DROP COLUMN subtotal_minor,
            MODIFY status ENUM('pending_application','pending_payment','confirmed','cancelled','refunded','waitlisted') NOT NULL",
        'ALTER TABLE events ADD COLUMN currency CHAR(3) NULL AFTER requirements, ADD COLUMN price_minor BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER currency',
        'UPDATE events e JOIN (SELECT event_id, MIN(id) AS id FROM event_prices GROUP BY event_id) f ON f.event_id = e.id JOIN event_prices p ON p.id = f.id SET e.currency = p.currency, e.price_minor = p.amount_minor',
        'DROP TABLE IF EXISTS event_prices',
        'ALTER TABLE events DROP COLUMN refund_on_cancel_percent, DROP COLUMN cancellation_window_hours, DROP COLUMN cancellation_policy, DROP COLUMN tax_inclusive,
            DROP COLUMN tax_rate_bp, DROP COLUMN tax_label, DROP COLUMN waitlist_enabled, DROP COLUMN registration_closes_at, DROP COLUMN registration_opens_at',
        "ALTER TABLE applications DROP COLUMN converted_at,
            MODIFY status ENUM('draft','submitted','under_review','info_requested','approved','invited','rejected','archived') NOT NULL DEFAULT 'draft'",
        "ALTER TABLE appointments DROP COLUMN expired_at, DROP COLUMN no_show_at, DROP COLUMN country,
            MODIFY calendar_sync_status ENUM('not_required','pending','synced','failed') NOT NULL DEFAULT 'pending',
            MODIFY status ENUM('pending_application','awaiting_approval','pending_payment','payment_verification','confirmed','rescheduled','cancelled','completed','payment_failed','refunded') NOT NULL",
        "ALTER TABLE payments DROP INDEX payments_reference_unique, DROP COLUMN reconciliation_note, DROP COLUMN reference,
            MODIFY status ENUM('created','pending','captured','failed','refunded','partially_refunded','cancelled') NOT NULL DEFAULT 'created'",
    ],
];

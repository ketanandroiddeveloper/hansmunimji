<?php

declare(strict_types=1);

$table = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

return [
    'up' => [
        "CREATE TABLE users (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(190) NOT NULL,
            name VARCHAR(190) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            status ENUM('active','disabled') NOT NULL DEFAULT 'active',
            totp_secret_enc TEXT NULL,
            totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
            failed_logins SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            lockouts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            last_login_at DATETIME NULL,
            password_changed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY users_email_unique (email)
        ) {$table}",

        "CREATE TABLE roles (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(60) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(255) NULL,
            is_system TINYINT(1) NOT NULL DEFAULT 0
        ) {$table}",

        "CREATE TABLE permissions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(80) NOT NULL UNIQUE,
            description VARCHAR(255) NOT NULL
        ) {$table}",

        "CREATE TABLE role_permissions (
            role_id INT UNSIGNED NOT NULL,
            permission_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (role_id, permission_id),
            CONSTRAINT rp_role_fk FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
            CONSTRAINT rp_permission_fk FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
        ) {$table}",

        "CREATE TABLE user_roles (
            user_id BIGINT UNSIGNED NOT NULL,
            role_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, role_id),
            CONSTRAINT ur_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT ur_role_fk FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
        ) {$table}",

        "CREATE TABLE user_sessions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            csrf_hash CHAR(64) NOT NULL,
            ip_hash CHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            revoked_at DATETIME NULL,
            KEY sessions_user_idx (user_id),
            KEY sessions_expiry_idx (expires_at),
            CONSTRAINT sessions_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) {$table}",

        "CREATE TABLE password_resets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            CONSTRAINT resets_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) {$table}",

        "CREATE TABLE login_challenges (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            CONSTRAINT challenges_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) {$table}",

        "CREATE TABLE rate_limits (
            id CHAR(64) PRIMARY KEY,
            bucket VARCHAR(60) NOT NULL,
            hits INT UNSIGNED NOT NULL DEFAULT 0,
            reset_at DATETIME NOT NULL,
            KEY rate_limits_reset_idx (reset_at)
        ) {$table}",

        "CREATE TABLE audit_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NULL,
            action VARCHAR(80) NOT NULL,
            entity_type VARCHAR(60) NULL,
            entity_id VARCHAR(64) NULL,
            ip_hash CHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            metadata JSON NULL,
            created_at DATETIME NOT NULL,
            KEY audit_user_idx (user_id, created_at),
            KEY audit_entity_idx (entity_type, entity_id),
            KEY audit_action_idx (action, created_at),
            CONSTRAINT audit_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) {$table}",
    ],
    'down' => [
        'DROP TABLE IF EXISTS audit_logs',
        'DROP TABLE IF EXISTS rate_limits',
        'DROP TABLE IF EXISTS login_challenges',
        'DROP TABLE IF EXISTS password_resets',
        'DROP TABLE IF EXISTS user_sessions',
        'DROP TABLE IF EXISTS user_roles',
        'DROP TABLE IF EXISTS role_permissions',
        'DROP TABLE IF EXISTS permissions',
        'DROP TABLE IF EXISTS roles',
        'DROP TABLE IF EXISTS users',
    ],
];

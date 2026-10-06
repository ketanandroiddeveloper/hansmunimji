<?php

declare(strict_types=1);

$table = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

return [
    'up' => [
        "CREATE TABLE media (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            disk ENUM('public','private') NOT NULL DEFAULT 'public',
            path VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime VARCHAR(100) NOT NULL,
            size_bytes INT UNSIGNED NOT NULL,
            width INT UNSIGNED NULL,
            height INT UNSIGNED NULL,
            alt VARCHAR(255) NOT NULL DEFAULT '',
            caption VARCHAR(500) NULL,
            category VARCHAR(60) NOT NULL DEFAULT 'general',
            focal_point VARCHAR(20) NULL,
            variants JSON NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY media_category_idx (category),
            CONSTRAINT media_user_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) {$table}",

        "CREATE TABLE practitioner_profiles (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(120) NOT NULL UNIQUE,
            full_name VARCHAR(190) NOT NULL,
            honorific VARCHAR(60) NULL,
            title VARCHAR(190) NULL,
            short_bio TEXT NULL,
            biography MEDIUMTEXT NULL,
            philosophy MEDIUMTEXT NULL,
            approach MEDIUMTEXT NULL,
            expertise JSON NULL,
            experience JSON NULL,
            portrait_media_id BIGINT UNSIGNED NULL,
            secondary_media_id BIGINT UNSIGNED NULL,
            same_as JSON NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            CONSTRAINT practitioner_portrait_fk FOREIGN KEY (portrait_media_id) REFERENCES media(id) ON DELETE SET NULL,
            CONSTRAINT practitioner_secondary_fk FOREIGN KEY (secondary_media_id) REFERENCES media(id) ON DELETE SET NULL
        ) {$table}",

        "CREATE TABLE qualifications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            practitioner_id BIGINT UNSIGNED NOT NULL,
            kind ENUM('qualification','certification','experience','publication','interview','speaking','award') NOT NULL,
            title VARCHAR(255) NOT NULL,
            institution VARCHAR(255) NULL,
            year SMALLINT UNSIGNED NULL,
            description TEXT NULL,
            url VARCHAR(500) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY qualifications_kind_idx (practitioner_id, kind, sort_order),
            CONSTRAINT qualifications_practitioner_fk FOREIGN KEY (practitioner_id) REFERENCES practitioner_profiles(id) ON DELETE CASCADE
        ) {$table}",

        "CREATE TABLE pages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(120) NOT NULL UNIQUE,
            type ENUM('page','legal') NOT NULL DEFAULT 'page',
            title VARCHAR(255) NOT NULL,
            sections JSON NULL,
            body MEDIUMTEXT NULL,
            status ENUM('draft','published') NOT NULL DEFAULT 'draft',
            published_at DATETIME NULL,
            updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY pages_type_idx (type, status),
            CONSTRAINT pages_user_fk FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) {$table}",

        "CREATE TABLE service_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(120) NOT NULL UNIQUE,
            name VARCHAR(190) NOT NULL,
            description TEXT NULL,
            sort_order INT NOT NULL DEFAULT 0
        ) {$table}",

        "CREATE TABLE services (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            category_id INT UNSIGNED NULL,
            slug VARCHAR(120) NOT NULL UNIQUE,
            numeral VARCHAR(8) NULL,
            title VARCHAR(190) NOT NULL,
            subtitle VARCHAR(255) NULL,
            summary TEXT NOT NULL,
            body MEDIUMTEXT NULL,
            highlights JSON NULL,
            offerings JSON NULL,
            cover_media_id BIGINT UNSIGNED NULL,
            booking_mode ENUM('direct','application','inquiry') NOT NULL DEFAULT 'application',
            duration_label VARCHAR(120) NULL,
            price_display VARCHAR(120) NULL,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            is_featured TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY services_published_idx (is_published, sort_order),
            CONSTRAINT services_category_fk FOREIGN KEY (category_id) REFERENCES service_categories(id) ON DELETE SET NULL,
            CONSTRAINT services_cover_fk FOREIGN KEY (cover_media_id) REFERENCES media(id) ON DELETE SET NULL
        ) {$table}",

        "CREATE TABLE faqs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            service_id BIGINT UNSIGNED NULL,
            question VARCHAR(500) NOT NULL,
            answer TEXT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY faqs_service_idx (service_id, is_published, sort_order),
            CONSTRAINT faqs_service_fk FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
        ) {$table}",

        "CREATE TABLE testimonials (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            quote TEXT NOT NULL,
            attribution VARCHAR(190) NOT NULL,
            role VARCHAR(190) NULL,
            consent_reference VARCHAR(190) NULL,
            is_authorized TINYINT(1) NOT NULL DEFAULT 0,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) {$table}",

        "CREATE TABLE cities (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            country CHAR(2) NOT NULL,
            timezone VARCHAR(64) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            UNIQUE KEY cities_name_country_unique (name, country)
        ) {$table}",

        "CREATE TABLE blog_categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(120) NOT NULL UNIQUE,
            name VARCHAR(190) NOT NULL,
            description TEXT NULL
        ) {$table}",

        "CREATE TABLE blog_posts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            category_id INT UNSIGNED NULL,
            author_id BIGINT UNSIGNED NULL,
            slug VARCHAR(160) NOT NULL UNIQUE,
            title VARCHAR(255) NOT NULL,
            excerpt TEXT NULL,
            body MEDIUMTEXT NULL,
            cover_media_id BIGINT UNSIGNED NULL,
            status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
            published_at DATETIME NULL,
            reading_minutes SMALLINT UNSIGNED NULL,
            tags JSON NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY blog_status_idx (status, published_at),
            CONSTRAINT blog_category_fk FOREIGN KEY (category_id) REFERENCES blog_categories(id) ON DELETE SET NULL,
            CONSTRAINT blog_author_fk FOREIGN KEY (author_id) REFERENCES practitioner_profiles(id) ON DELETE SET NULL,
            CONSTRAINT blog_cover_fk FOREIGN KEY (cover_media_id) REFERENCES media(id) ON DELETE SET NULL
        ) {$table}",

        "CREATE TABLE audio_tracks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(160) NOT NULL UNIQUE,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            category ENUM('guided_meditation','sonic_healing','vedic_chant','catharsis','sample') NOT NULL,
            duration_seconds INT UNSIGNED NULL,
            file_path VARCHAR(255) NULL,
            mime VARCHAR(100) NULL,
            size_bytes BIGINT UNSIGNED NULL,
            cover_media_id BIGINT UNSIGNED NULL,
            access ENUM('public','clients','private') NOT NULL DEFAULT 'private',
            is_featured TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            status ENUM('draft','published') NOT NULL DEFAULT 'draft',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            KEY audio_status_idx (status, category, sort_order),
            CONSTRAINT audio_cover_fk FOREIGN KEY (cover_media_id) REFERENCES media(id) ON DELETE SET NULL
        ) {$table}",

        "CREATE TABLE seo_metadata (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            path VARCHAR(255) NOT NULL UNIQUE,
            title VARCHAR(255) NULL,
            description VARCHAR(500) NULL,
            canonical VARCHAR(500) NULL,
            og_image_media_id BIGINT UNSIGNED NULL,
            robots VARCHAR(60) NULL,
            updated_at DATETIME NOT NULL,
            CONSTRAINT seo_og_fk FOREIGN KEY (og_image_media_id) REFERENCES media(id) ON DELETE SET NULL
        ) {$table}",

        "CREATE TABLE settings (
            `key` VARCHAR(120) PRIMARY KEY,
            value JSON NOT NULL,
            is_public TINYINT(1) NOT NULL DEFAULT 0,
            updated_by BIGINT UNSIGNED NULL,
            updated_at DATETIME NOT NULL,
            CONSTRAINT settings_user_fk FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) {$table}",
    ],
    'down' => [
        'DROP TABLE IF EXISTS settings',
        'DROP TABLE IF EXISTS seo_metadata',
        'DROP TABLE IF EXISTS audio_tracks',
        'DROP TABLE IF EXISTS blog_posts',
        'DROP TABLE IF EXISTS blog_categories',
        'DROP TABLE IF EXISTS cities',
        'DROP TABLE IF EXISTS testimonials',
        'DROP TABLE IF EXISTS faqs',
        'DROP TABLE IF EXISTS services',
        'DROP TABLE IF EXISTS service_categories',
        'DROP TABLE IF EXISTS pages',
        'DROP TABLE IF EXISTS qualifications',
        'DROP TABLE IF EXISTS practitioner_profiles',
        'DROP TABLE IF EXISTS media',
    ],
];

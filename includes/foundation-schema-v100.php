<?php
declare(strict_types=1);

const DAVESTUNES_FOUNDATION_V100='foundation-v1-section1-20260929';

function dt_foundation_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=dt_db();
    foreach(['users','user_profiles','auth_login_attempts','artists','artist_memberships','artist_authority_events'] as $table){
        if(!dt_table_exists($pdo,$table))return false;
    }
    return true;
}

function dt_foundation_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=dt_db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(254) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        account_status VARCHAR(20) NOT NULL DEFAULT 'active',
        email_verified_at DATETIME NULL,
        last_login_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_users_email (email),
        INDEX idx_users_status (account_status,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_profiles (
        user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        display_name VARCHAR(120) NOT NULL,
        handle VARCHAR(80) NULL,
        bio TEXT NULL,
        avatar_path VARCHAR(500) NOT NULL DEFAULT '',
        timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_profiles_handle (handle),
        CONSTRAINT fk_user_profile_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS auth_login_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email_hash CHAR(64) NOT NULL,
        ip_hash CHAR(64) NOT NULL,
        was_successful TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_auth_attempt_email (email_hash,created_at,id),
        INDEX idx_auth_attempt_ip (ip_hash,created_at,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS artists (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        owner_user_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(190) NOT NULL,
        slug VARCHAR(190) NOT NULL,
        bio TEXT NULL,
        genres_json JSON NULL,
        location VARCHAR(190) NOT NULL DEFAULT '',
        profile_image_path VARCHAR(500) NOT NULL DEFAULT '',
        cover_image_path VARCHAR(500) NOT NULL DEFAULT '',
        website_url VARCHAR(500) NOT NULL DEFAULT '',
        instagram_url VARCHAR(500) NOT NULL DEFAULT '',
        tiktok_url VARCHAR(500) NOT NULL DEFAULT '',
        youtube_url VARCHAR(500) NOT NULL DEFAULT '',
        spotify_url VARCHAR(500) NOT NULL DEFAULT '',
        apple_music_url VARCHAR(500) NOT NULL DEFAULT '',
        artist_status VARCHAR(30) NOT NULL DEFAULT 'active',
        verification_status VARCHAR(30) NOT NULL DEFAULT 'unverified',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_artists_slug (slug),
        INDEX idx_artists_owner (owner_user_id,artist_status,id),
        CONSTRAINT fk_artist_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS artist_memberships (
        artist_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        artist_role VARCHAR(30) NOT NULL DEFAULT 'viewer',
        membership_status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_by_user_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (artist_id,user_id),
        INDEX idx_artist_membership_user (user_id,membership_status,artist_role,artist_id),
        CONSTRAINT fk_artist_membership_artist FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE CASCADE,
        CONSTRAINT fk_artist_membership_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_artist_membership_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS artist_authority_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        artist_id BIGINT UNSIGNED NOT NULL,
        event_type VARCHAR(80) NOT NULL,
        actor_user_id BIGINT UNSIGNED NULL,
        subject_user_id BIGINT UNSIGNED NULL,
        metadata_json JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_artist_authority_artist (artist_id,created_at,id),
        INDEX idx_artist_authority_actor (actor_user_id,created_at,id),
        CONSTRAINT fk_artist_authority_artist FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE RESTRICT,
        CONSTRAINT fk_artist_authority_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
        CONSTRAINT fk_artist_authority_subject FOREIGN KEY (subject_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

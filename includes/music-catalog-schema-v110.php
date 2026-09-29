<?php
declare(strict_types=1);

const DAVESTUNES_CATALOG_V110='foundation-v1-section2-catalog-20260929';

function dt_catalog_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=dt_db();
    foreach([
        'music_recordings_v110',
        'music_releases_v110',
        'music_release_editions_v110',
        'music_release_tracks_v110',
        'music_catalog_events_v110',
    ] as $table){
        if(!dt_table_exists($pdo,$table))return false;
    }
    return true;
}

function dt_catalog_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=dt_db();
    if(!dt_foundation_schema_ready($pdo))throw new RuntimeException('Foundation schema must be installed before the music catalog.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS music_recordings_v110 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        artist_id BIGINT UNSIGNED NOT NULL,
        created_by_user_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(190) NOT NULL,
        version_label VARCHAR(120) NOT NULL DEFAULT '',
        isrc VARCHAR(20) NULL,
        duration_ms INT UNSIGNED NULL,
        explicit_content TINYINT(1) NOT NULL DEFAULT 0,
        recording_status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_music_recording_isrc_v110 (isrc),
        INDEX idx_music_recording_artist_v110 (artist_id,recording_status,updated_at,id),
        CONSTRAINT fk_music_recording_artist_v110 FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE RESTRICT,
        CONSTRAINT fk_music_recording_creator_v110 FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS music_releases_v110 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        artist_id BIGINT UNSIGNED NOT NULL,
        created_by_user_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(190) NOT NULL,
        slug VARCHAR(190) NOT NULL,
        release_type VARCHAR(20) NOT NULL DEFAULT 'album',
        release_status VARCHAR(20) NOT NULL DEFAULT 'draft',
        release_date DATE NULL,
        published_at DATETIME NULL,
        description TEXT NULL,
        cover_path VARCHAR(500) NOT NULL DEFAULT '',
        upc VARCHAR(32) NULL,
        label_name VARCHAR(190) NOT NULL DEFAULT '',
        copyright_notice VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_music_release_artist_slug_v110 (artist_id,slug),
        UNIQUE KEY uq_music_release_upc_v110 (upc),
        INDEX idx_music_release_artist_v110 (artist_id,release_status,release_date,id),
        CONSTRAINT fk_music_release_artist_v110 FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE RESTRICT,
        CONSTRAINT fk_music_release_creator_v110 FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS music_release_editions_v110 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        release_id BIGINT UNSIGNED NOT NULL,
        created_by_user_id BIGINT UNSIGNED NOT NULL,
        edition_name VARCHAR(190) NOT NULL,
        edition_code VARCHAR(120) NOT NULL,
        edition_format VARCHAR(30) NOT NULL DEFAULT 'digital',
        edition_status VARCHAR(20) NOT NULL DEFAULT 'active',
        grants_digital_access TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_music_release_edition_code_v110 (release_id,edition_code),
        INDEX idx_music_release_edition_release_v110 (release_id,edition_status,id),
        CONSTRAINT fk_music_release_edition_release_v110 FOREIGN KEY (release_id) REFERENCES music_releases_v110(id) ON DELETE CASCADE,
        CONSTRAINT fk_music_release_edition_creator_v110 FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS music_release_tracks_v110 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        release_id BIGINT UNSIGNED NOT NULL,
        recording_id BIGINT UNSIGNED NOT NULL,
        disc_number SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        track_number SMALLINT UNSIGNED NOT NULL,
        sequence_order INT UNSIGNED NOT NULL DEFAULT 0,
        display_title VARCHAR(190) NULL,
        is_bonus TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_music_release_track_slot_v110 (release_id,disc_number,track_number),
        INDEX idx_music_release_track_recording_v110 (recording_id,release_id,id),
        CONSTRAINT fk_music_release_track_release_v110 FOREIGN KEY (release_id) REFERENCES music_releases_v110(id) ON DELETE CASCADE,
        CONSTRAINT fk_music_release_track_recording_v110 FOREIGN KEY (recording_id) REFERENCES music_recordings_v110(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS music_catalog_events_v110 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        artist_id BIGINT UNSIGNED NOT NULL,
        entity_type VARCHAR(30) NOT NULL,
        entity_id BIGINT UNSIGNED NOT NULL,
        event_type VARCHAR(80) NOT NULL,
        actor_user_id BIGINT UNSIGNED NULL,
        metadata_json JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_music_catalog_event_artist_v110 (artist_id,created_at,id),
        INDEX idx_music_catalog_event_entity_v110 (entity_type,entity_id,created_at,id),
        CONSTRAINT fk_music_catalog_event_artist_v110 FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE RESTRICT,
        CONSTRAINT fk_music_catalog_event_actor_v110 FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

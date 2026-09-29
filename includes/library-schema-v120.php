<?php
declare(strict_types=1);

const DAVESTUNES_LIBRARY_V120='foundation-v1-section3-library-entitlements-20260929';

function dt_library_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=dt_db();
    foreach([
        'music_entitlements_v120',
        'music_entitlement_events_v120',
        'user_saved_releases_v120',
        'user_saved_recordings_v120',
        'user_artist_follows_v120',
        'music_crates_v120',
        'music_crate_items_v120',
        'music_playlists_v120',
        'music_playlist_recordings_v120',
    ] as $table){
        if(!dt_table_exists($pdo,$table))return false;
    }
    return true;
}

function dt_library_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=dt_db();
    if(!dt_catalog_schema_ready($pdo))throw new RuntimeException('Canonical catalog must be installed before the personal library.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS music_entitlements_v120 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        grant_key VARCHAR(190) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        resource_type VARCHAR(30) NOT NULL,
        resource_id BIGINT UNSIGNED NOT NULL,
        entitlement_type VARCHAR(20) NOT NULL DEFAULT 'access',
        source_type VARCHAR(30) NOT NULL,
        source_ref VARCHAR(190) NOT NULL DEFAULT '',
        entitlement_status VARCHAR(20) NOT NULL DEFAULT 'active',
        starts_at DATETIME NULL,
        ends_at DATETIME NULL,
        granted_by_user_id BIGINT UNSIGNED NULL,
        revoked_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_music_entitlement_grant_v120 (grant_key),
        INDEX idx_music_entitlement_user_v120 (user_id,entitlement_status,resource_type,resource_id,id),
        INDEX idx_music_entitlement_resource_v120 (resource_type,resource_id,entitlement_status,id),
        CONSTRAINT fk_music_entitlement_user_v120 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_music_entitlement_grantor_v120 FOREIGN KEY (granted_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS music_entitlement_events_v120 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        entitlement_id BIGINT UNSIGNED NOT NULL,
        event_type VARCHAR(80) NOT NULL,
        actor_user_id BIGINT UNSIGNED NULL,
        metadata_json JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_music_entitlement_event_v120 (entitlement_id,created_at,id),
        CONSTRAINT fk_music_entitlement_event_entitlement_v120 FOREIGN KEY (entitlement_id) REFERENCES music_entitlements_v120(id) ON DELETE RESTRICT,
        CONSTRAINT fk_music_entitlement_event_actor_v120 FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_saved_releases_v120 (
        user_id BIGINT UNSIGNED NOT NULL,
        release_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id,release_id),
        INDEX idx_user_saved_release_v120 (user_id,created_at,release_id),
        CONSTRAINT fk_user_saved_release_user_v120 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_user_saved_release_release_v120 FOREIGN KEY (release_id) REFERENCES music_releases_v110(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_saved_recordings_v120 (
        user_id BIGINT UNSIGNED NOT NULL,
        recording_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id,recording_id),
        INDEX idx_user_saved_recording_v120 (user_id,created_at,recording_id),
        CONSTRAINT fk_user_saved_recording_user_v120 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_user_saved_recording_recording_v120 FOREIGN KEY (recording_id) REFERENCES music_recordings_v110(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_artist_follows_v120 (
        user_id BIGINT UNSIGNED NOT NULL,
        artist_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id,artist_id),
        INDEX idx_user_artist_follow_v120 (user_id,created_at,artist_id),
        CONSTRAINT fk_user_artist_follow_user_v120 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_user_artist_follow_artist_v120 FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS music_crates_v120 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        crate_name VARCHAR(120) NOT NULL,
        crate_slug VARCHAR(120) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_music_crate_user_slug_v120 (user_id,crate_slug),
        INDEX idx_music_crate_user_v120 (user_id,updated_at,id),
        CONSTRAINT fk_music_crate_user_v120 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS music_crate_items_v120 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        crate_id BIGINT UNSIGNED NOT NULL,
        resource_type VARCHAR(30) NOT NULL,
        resource_id BIGINT UNSIGNED NOT NULL,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_music_crate_item_v120 (crate_id,resource_type,resource_id),
        INDEX idx_music_crate_item_order_v120 (crate_id,sort_order,id),
        CONSTRAINT fk_music_crate_item_crate_v120 FOREIGN KEY (crate_id) REFERENCES music_crates_v120(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS music_playlists_v120 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        playlist_name VARCHAR(120) NOT NULL,
        description TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_music_playlist_user_v120 (user_id,updated_at,id),
        CONSTRAINT fk_music_playlist_user_v120 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS music_playlist_recordings_v120 (
        playlist_id BIGINT UNSIGNED NOT NULL,
        recording_id BIGINT UNSIGNED NOT NULL,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (playlist_id,recording_id),
        INDEX idx_music_playlist_recording_order_v120 (playlist_id,sort_order,recording_id),
        CONSTRAINT fk_music_playlist_recording_playlist_v120 FOREIGN KEY (playlist_id) REFERENCES music_playlists_v120(id) ON DELETE CASCADE,
        CONSTRAINT fk_music_playlist_recording_recording_v120 FOREIGN KEY (recording_id) REFERENCES music_recordings_v110(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

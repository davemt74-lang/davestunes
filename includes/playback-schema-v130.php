<?php
declare(strict_types=1);

const DAVESTUNES_PLAYBACK_V130='foundation-v1-section4-playback-20260929';

function dt_playback_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=dt_db();
    foreach([
        'recording_media_v130',
        'playback_sessions_v130',
        'playback_queue_items_v130',
        'playback_listens_v130',
        'playback_events_v130',
    ] as $table){
        if(!dt_table_exists($pdo,$table))return false;
    }
    return true;
}

function dt_playback_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=dt_db();
    if(!dt_library_schema_ready($pdo))throw new RuntimeException('Personal library must be installed before playback.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS recording_media_v130 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        recording_id BIGINT UNSIGNED NOT NULL,
        uploaded_by_user_id BIGINT UNSIGNED NOT NULL,
        media_role VARCHAR(20) NOT NULL,
        storage_driver VARCHAR(20) NOT NULL DEFAULT 'local',
        storage_key VARCHAR(500) NOT NULL,
        mime_type VARCHAR(100) NOT NULL,
        byte_size BIGINT UNSIGNED NOT NULL,
        sha256 CHAR(64) NOT NULL,
        duration_ms INT UNSIGNED NULL,
        preview_start_ms INT UNSIGNED NULL,
        preview_end_ms INT UNSIGNED NULL,
        asset_status VARCHAR(20) NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_recording_media_recording_v130 (recording_id,media_role,asset_status,id),
        UNIQUE KEY uq_recording_media_sha_v130 (recording_id,media_role,sha256),
        CONSTRAINT fk_recording_media_recording_v130 FOREIGN KEY (recording_id) REFERENCES music_recordings_v110(id) ON DELETE CASCADE,
        CONSTRAINT fk_recording_media_uploader_v130 FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS playback_sessions_v130 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        session_key VARCHAR(80) NOT NULL,
        current_recording_id BIGINT UNSIGNED NULL,
        playback_state VARCHAR(20) NOT NULL DEFAULT 'paused',
        position_ms INT UNSIGNED NOT NULL DEFAULT 0,
        volume DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
        repeat_mode VARCHAR(20) NOT NULL DEFAULT 'off',
        shuffle_enabled TINYINT(1) NOT NULL DEFAULT 0,
        queue_revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_source_type VARCHAR(30) NOT NULL DEFAULT '',
        last_source_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_playback_session_user_key_v130 (user_id,session_key),
        INDEX idx_playback_session_user_v130 (user_id,updated_at,id),
        CONSTRAINT fk_playback_session_user_v130 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_playback_session_recording_v130 FOREIGN KEY (current_recording_id) REFERENCES music_recordings_v110(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS playback_queue_items_v130 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        session_id BIGINT UNSIGNED NOT NULL,
        recording_id BIGINT UNSIGNED NOT NULL,
        queue_position INT UNSIGNED NOT NULL,
        source_type VARCHAR(30) NOT NULL DEFAULT '',
        source_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_playback_queue_position_v130 (session_id,queue_position),
        INDEX idx_playback_queue_recording_v130 (session_id,recording_id,id),
        CONSTRAINT fk_playback_queue_session_v130 FOREIGN KEY (session_id) REFERENCES playback_sessions_v130(id) ON DELETE CASCADE,
        CONSTRAINT fk_playback_queue_recording_v130 FOREIGN KEY (recording_id) REFERENCES music_recordings_v110(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS playback_listens_v130 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        play_token CHAR(36) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        session_id BIGINT UNSIGNED NOT NULL,
        recording_id BIGINT UNSIGNED NOT NULL,
        access_mode VARCHAR(20) NOT NULL,
        source_type VARCHAR(30) NOT NULL DEFAULT '',
        source_id BIGINT UNSIGNED NULL,
        started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_position_ms INT UNSIGNED NOT NULL DEFAULT 0,
        listened_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
        completed_at DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_playback_listen_token_v130 (play_token),
        INDEX idx_playback_listen_user_v130 (user_id,started_at,id),
        INDEX idx_playback_listen_recording_v130 (recording_id,started_at,id),
        CONSTRAINT fk_playback_listen_user_v130 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_playback_listen_session_v130 FOREIGN KEY (session_id) REFERENCES playback_sessions_v130(id) ON DELETE CASCADE,
        CONSTRAINT fk_playback_listen_recording_v130 FOREIGN KEY (recording_id) REFERENCES music_recordings_v110(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS playback_events_v130 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        session_id BIGINT UNSIGNED NULL,
        recording_id BIGINT UNSIGNED NULL,
        event_type VARCHAR(80) NOT NULL,
        metadata_json JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_playback_event_user_v130 (user_id,created_at,id),
        INDEX idx_playback_event_session_v130 (session_id,created_at,id),
        CONSTRAINT fk_playback_event_user_v130 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_playback_event_session_v130 FOREIGN KEY (session_id) REFERENCES playback_sessions_v130(id) ON DELETE SET NULL,
        CONSTRAINT fk_playback_event_recording_v130 FOREIGN KEY (recording_id) REFERENCES music_recordings_v110(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

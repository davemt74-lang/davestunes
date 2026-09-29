<?php
declare(strict_types=1);

const DAVESTUNES_DESKTOP_OBJECTS_V220='music-desktop-v1-section3-objects-20260929';

function dt_desktop_objects_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=dt_db();
    foreach(['desktop_layouts_v220','desktop_objects_v220','desktop_layout_events_v220'] as $table){
        if(!dt_table_exists($pdo,$table))return false;
    }
    return true;
}

function dt_desktop_objects_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=dt_db();
    if(!dt_playback_schema_ready($pdo))throw new RuntimeException('Playback foundation must be installed before Desktop objects.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS desktop_layouts_v220 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        layout_key VARCHAR(80) NOT NULL DEFAULT 'primary',
        template_id VARCHAR(80) NOT NULL DEFAULT 'midnight-desk',
        revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_desktop_layout_user_key_v220 (user_id,layout_key),
        INDEX idx_desktop_layout_user_v220 (user_id,updated_at,id),
        CONSTRAINT fk_desktop_layout_user_v220 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS desktop_objects_v220 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        layout_id BIGINT UNSIGNED NOT NULL,
        object_uuid CHAR(36) NOT NULL,
        object_type VARCHAR(30) NOT NULL,
        resource_id BIGINT UNSIGNED NULL,
        x_ratio DECIMAL(8,5) NOT NULL DEFAULT 0.10000,
        y_ratio DECIMAL(8,5) NOT NULL DEFAULT 0.10000,
        rotation_deg DECIMAL(8,3) NOT NULL DEFAULT 0.000,
        scale_factor DECIMAL(7,4) NOT NULL DEFAULT 1.0000,
        z_order INT UNSIGNED NOT NULL DEFAULT 1,
        is_locked TINYINT(1) NOT NULL DEFAULT 0,
        is_pinned TINYINT(1) NOT NULL DEFAULT 0,
        object_status VARCHAR(20) NOT NULL DEFAULT 'active',
        payload_json JSON NULL,
        object_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
        removed_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_desktop_object_uuid_v220 (object_uuid),
        INDEX idx_desktop_object_layout_v220 (layout_id,object_status,z_order,id),
        INDEX idx_desktop_object_resource_v220 (layout_id,object_type,resource_id,object_status,id),
        CONSTRAINT fk_desktop_object_layout_v220 FOREIGN KEY (layout_id) REFERENCES desktop_layouts_v220(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS desktop_layout_events_v220 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        layout_id BIGINT UNSIGNED NOT NULL,
        object_uuid CHAR(36) NULL,
        event_type VARCHAR(80) NOT NULL,
        actor_user_id BIGINT UNSIGNED NOT NULL,
        metadata_json JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_desktop_layout_event_layout_v220 (layout_id,created_at,id),
        INDEX idx_desktop_layout_event_object_v220 (object_uuid,created_at,id),
        CONSTRAINT fk_desktop_layout_event_layout_v220 FOREIGN KEY (layout_id) REFERENCES desktop_layouts_v220(id) ON DELETE CASCADE,
        CONSTRAINT fk_desktop_layout_event_actor_v220 FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

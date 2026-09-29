<?php
declare(strict_types=1);

const DAVESTUNES_DESKTOP_OBJECTS_V220='music-desktop-v1-section3-objects-20260929';

function dt_desktop_object_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=dt_db();
    return dt_table_exists($pdo,'desktop_objects_v220')
        &&dt_table_exists($pdo,'desktop_object_events_v220');
}

function dt_desktop_object_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=dt_db();
    if(!dt_foundation_schema_ready($pdo))throw new RuntimeException('Foundation schema must be installed before Desktop objects.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS desktop_objects_v220 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        object_key VARCHAR(120) NOT NULL,
        object_type VARCHAR(80) NOT NULL,
        resource_type VARCHAR(40) NULL,
        resource_id BIGINT UNSIGNED NULL,
        label VARCHAR(190) NOT NULL DEFAULT '',
        x_norm DECIMAL(9,6) NOT NULL DEFAULT 0.500000,
        y_norm DECIMAL(9,6) NOT NULL DEFAULT 0.500000,
        rotation_deg DECIMAL(8,3) NOT NULL DEFAULT 0.000,
        scale_factor DECIMAL(7,4) NOT NULL DEFAULT 1.0000,
        z_order INT UNSIGNED NOT NULL DEFAULT 1,
        is_pinned TINYINT(1) NOT NULL DEFAULT 0,
        payload_json JSON NULL,
        revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_desktop_object_user_key_v220 (user_id,object_key),
        INDEX idx_desktop_object_user_z_v220 (user_id,z_order,id),
        INDEX idx_desktop_object_resource_v220 (user_id,resource_type,resource_id,id),
        CONSTRAINT fk_desktop_object_user_v220 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS desktop_object_events_v220 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        object_id BIGINT UNSIGNED NULL,
        event_type VARCHAR(80) NOT NULL,
        object_key VARCHAR(120) NOT NULL DEFAULT '',
        metadata_json JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_desktop_object_event_user_v220 (user_id,created_at,id),
        INDEX idx_desktop_object_event_object_v220 (object_id,created_at,id),
        CONSTRAINT fk_desktop_object_event_user_v220 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_desktop_object_event_object_v220 FOREIGN KEY (object_id) REFERENCES desktop_objects_v220(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

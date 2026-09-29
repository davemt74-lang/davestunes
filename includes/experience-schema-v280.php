<?php
declare(strict_types=1);

const DAVESTUNES_EXPERIENCE_V280='music-desktop-v1-section9-experience-20260929';

function dt_experience_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=dt_db();
    foreach([
        'experiences_v280','experience_versions_v280','experience_scenes_v280',
        'experience_layers_v280','experience_flow_nodes_v280','experience_flow_edges_v280','experience_events_v280'
    ] as $table)if(!dt_table_exists($pdo,$table))return false;
    return true;
}

function dt_experience_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=dt_db();
    if(!dt_foundation_schema_ready($pdo)||!dt_catalog_schema_ready($pdo))throw new RuntimeException('Foundation and catalog schemas must be installed before experiences.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS experiences_v280 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        owner_type VARCHAR(30) NOT NULL,
        owner_id BIGINT UNSIGNED NOT NULL,
        experience_key VARCHAR(100) NOT NULL DEFAULT 'default',
        name VARCHAR(190) NOT NULL,
        active_version_id BIGINT UNSIGNED NULL,
        created_by_user_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_experience_owner_key_v280 (owner_type,owner_id,experience_key),
        INDEX idx_experience_owner_v280 (owner_type,owner_id,id),
        CONSTRAINT fk_experience_creator_v280 FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS experience_versions_v280 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        experience_id BIGINT UNSIGNED NOT NULL,
        version_number INT UNSIGNED NOT NULL,
        version_status VARCHAR(20) NOT NULL DEFAULT 'draft',
        manifest_json JSON NULL,
        manifest_sha256 CHAR(64) NULL,
        created_by_user_id BIGINT UNSIGNED NOT NULL,
        published_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_experience_version_v280 (experience_id,version_number),
        INDEX idx_experience_version_status_v280 (experience_id,version_status,version_number),
        CONSTRAINT fk_experience_version_experience_v280 FOREIGN KEY (experience_id) REFERENCES experiences_v280(id) ON DELETE CASCADE,
        CONSTRAINT fk_experience_version_creator_v280 FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS experience_scenes_v280 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        version_id BIGINT UNSIGNED NOT NULL,
        scene_key VARCHAR(100) NOT NULL,
        title VARCHAR(190) NOT NULL,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        weight DECIMAL(8,3) NOT NULL DEFAULT 1.000,
        is_enabled TINYINT(1) NOT NULL DEFAULT 1,
        settings_json JSON NULL,
        UNIQUE KEY uq_experience_scene_key_v280 (version_id,scene_key),
        INDEX idx_experience_scene_order_v280 (version_id,sort_order,id),
        CONSTRAINT fk_experience_scene_version_v280 FOREIGN KEY (version_id) REFERENCES experience_versions_v280(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS experience_layers_v280 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        scene_id BIGINT UNSIGNED NOT NULL,
        layer_key VARCHAR(100) NOT NULL,
        layer_type VARCHAR(60) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        settings_json JSON NULL,
        UNIQUE KEY uq_experience_layer_key_v280 (scene_id,layer_key),
        INDEX idx_experience_layer_order_v280 (scene_id,sort_order,id),
        CONSTRAINT fk_experience_layer_scene_v280 FOREIGN KEY (scene_id) REFERENCES experience_scenes_v280(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS experience_flow_nodes_v280 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        version_id BIGINT UNSIGNED NOT NULL,
        node_key VARCHAR(100) NOT NULL,
        node_type VARCHAR(80) NOT NULL,
        scene_key VARCHAR(100) NULL,
        position_x DECIMAL(10,3) NOT NULL DEFAULT 0,
        position_y DECIMAL(10,3) NOT NULL DEFAULT 0,
        settings_json JSON NULL,
        UNIQUE KEY uq_experience_node_key_v280 (version_id,node_key),
        INDEX idx_experience_node_type_v280 (version_id,node_type,id),
        CONSTRAINT fk_experience_node_version_v280 FOREIGN KEY (version_id) REFERENCES experience_versions_v280(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS experience_flow_edges_v280 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        version_id BIGINT UNSIGNED NOT NULL,
        edge_key VARCHAR(100) NOT NULL,
        from_node_key VARCHAR(100) NOT NULL,
        from_port VARCHAR(80) NOT NULL DEFAULT 'out',
        to_node_key VARCHAR(100) NOT NULL,
        to_port VARCHAR(80) NOT NULL DEFAULT 'in',
        condition_json JSON NULL,
        UNIQUE KEY uq_experience_edge_key_v280 (version_id,edge_key),
        INDEX idx_experience_edge_nodes_v280 (version_id,from_node_key,to_node_key,id),
        CONSTRAINT fk_experience_edge_version_v280 FOREIGN KEY (version_id) REFERENCES experience_versions_v280(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS experience_events_v280 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        experience_id BIGINT UNSIGNED NOT NULL,
        version_id BIGINT UNSIGNED NULL,
        actor_user_id BIGINT UNSIGNED NULL,
        event_type VARCHAR(80) NOT NULL,
        metadata_json JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_experience_event_v280 (experience_id,created_at,id),
        CONSTRAINT fk_experience_event_experience_v280 FOREIGN KEY (experience_id) REFERENCES experiences_v280(id) ON DELETE CASCADE,
        CONSTRAINT fk_experience_event_version_v280 FOREIGN KEY (version_id) REFERENCES experience_versions_v280(id) ON DELETE SET NULL,
        CONSTRAINT fk_experience_event_actor_v280 FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $fk=$pdo->prepare("SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='experiences_v280' AND constraint_name='fk_experience_active_version_v280' LIMIT 1");
    $fk->execute();
    if(!$fk->fetchColumn())$pdo->exec("ALTER TABLE experiences_v280 ADD CONSTRAINT fk_experience_active_version_v280 FOREIGN KEY (active_version_id) REFERENCES experience_versions_v280(id) ON DELETE SET NULL");
}

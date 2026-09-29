<?php
declare(strict_types=1);

const DAVESTUNES_FEATURED_V270='music-desktop-v1-section8-featured-20260929';

function dt_featured_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=dt_db();
    return dt_table_exists($pdo,'featured_posts_v270')&&dt_table_exists($pdo,'featured_post_events_v270');
}

function dt_featured_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=dt_db();
    if(!dt_foundation_schema_ready($pdo)||!dt_catalog_schema_ready($pdo))throw new RuntimeException('Foundation and catalog schemas must be installed before featured content.');
    $pdo->exec("CREATE TABLE IF NOT EXISTS featured_posts_v270 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        content_type VARCHAR(30) NOT NULL,
        content_id BIGINT UNSIGNED NOT NULL,
        headline VARCHAR(190) NOT NULL DEFAULT '',
        body_text VARCHAR(500) NOT NULL DEFAULT '',
        placement VARCHAR(40) NOT NULL DEFAULT 'user-desktop',
        post_status VARCHAR(20) NOT NULL DEFAULT 'draft',
        priority INT NOT NULL DEFAULT 0,
        starts_at DATETIME NULL,
        ends_at DATETIME NULL,
        created_by_user_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_featured_active_v270 (placement,post_status,starts_at,ends_at,priority,id),
        INDEX idx_featured_content_v270 (content_type,content_id,id),
        CONSTRAINT fk_featured_creator_v270 FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS featured_post_events_v270 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        featured_post_id BIGINT UNSIGNED NULL,
        actor_user_id BIGINT UNSIGNED NULL,
        event_type VARCHAR(80) NOT NULL,
        metadata_json JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_featured_event_post_v270 (featured_post_id,created_at,id),
        CONSTRAINT fk_featured_event_post_v270 FOREIGN KEY (featured_post_id) REFERENCES featured_posts_v270(id) ON DELETE SET NULL,
        CONSTRAINT fk_featured_event_actor_v270 FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

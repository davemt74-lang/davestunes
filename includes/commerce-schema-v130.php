<?php
declare(strict_types=1);

const DAVESTUNES_COMMERCE_V130='foundation-v1-section4-commerce-20260929';

function dt_commerce_schema_ready(?PDO $pdo=null): bool
{
    $pdo??=dt_db();
    foreach(['music_offers_v130','music_orders_v130','music_order_items_v130','music_order_events_v130','music_payment_events_v130'] as $table){
        if(!dt_table_exists($pdo,$table))return false;
    }
    return true;
}

function dt_commerce_ensure_schema(?PDO $pdo=null): void
{
    $pdo??=dt_db();
    if(!dt_library_schema_ready($pdo))throw new RuntimeException('Personal library and entitlement schema must be installed before commerce.');

    $pdo->exec("CREATE TABLE IF NOT EXISTS music_offers_v130 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        artist_id BIGINT UNSIGNED NOT NULL,
        created_by_user_id BIGINT UNSIGNED NOT NULL,
        resource_type VARCHAR(30) NOT NULL,
        resource_id BIGINT UNSIGNED NOT NULL,
        offer_name VARCHAR(190) NOT NULL,
        sku VARCHAR(120) NOT NULL,
        price_cents BIGINT UNSIGNED NOT NULL DEFAULT 0,
        currency CHAR(3) NOT NULL DEFAULT 'USD',
        grants_entitlement_type VARCHAR(20) NOT NULL DEFAULT 'own',
        offer_status VARCHAR(20) NOT NULL DEFAULT 'draft',
        sale_starts_at DATETIME NULL,
        sale_ends_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_music_offer_sku_v130 (sku),
        INDEX idx_music_offer_artist_v130 (artist_id,offer_status,updated_at,id),
        INDEX idx_music_offer_resource_v130 (resource_type,resource_id,offer_status,id),
        CONSTRAINT fk_music_offer_artist_v130 FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE RESTRICT,
        CONSTRAINT fk_music_offer_creator_v130 FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS music_orders_v130 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_number VARCHAR(48) NOT NULL,
        checkout_key VARCHAR(190) NOT NULL,
        cart_hash CHAR(64) NOT NULL,
        buyer_user_id BIGINT UNSIGNED NOT NULL,
        order_status VARCHAR(20) NOT NULL DEFAULT 'pending',
        currency CHAR(3) NOT NULL,
        subtotal_cents BIGINT UNSIGNED NOT NULL,
        total_cents BIGINT UNSIGNED NOT NULL,
        payment_provider VARCHAR(40) NOT NULL DEFAULT '',
        provider_payment_ref VARCHAR(190) NOT NULL DEFAULT '',
        expires_at DATETIME NOT NULL,
        paid_at DATETIME NULL,
        fulfilled_at DATETIME NULL,
        refunded_at DATETIME NULL,
        cancelled_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_music_order_number_v130 (order_number),
        UNIQUE KEY uq_music_order_checkout_v130 (checkout_key),
        INDEX idx_music_order_buyer_v130 (buyer_user_id,created_at,id),
        INDEX idx_music_order_status_v130 (order_status,expires_at,id),
        CONSTRAINT fk_music_order_buyer_v130 FOREIGN KEY (buyer_user_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS music_order_items_v130 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        offer_id BIGINT UNSIGNED NOT NULL,
        resource_type VARCHAR(30) NOT NULL,
        resource_id BIGINT UNSIGNED NOT NULL,
        offer_name_snapshot VARCHAR(190) NOT NULL,
        sku_snapshot VARCHAR(120) NOT NULL,
        unit_price_cents BIGINT UNSIGNED NOT NULL,
        quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        entitlement_type VARCHAR(20) NOT NULL DEFAULT 'own',
        fulfillment_status VARCHAR(20) NOT NULL DEFAULT 'pending',
        entitlement_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_music_order_offer_v130 (order_id,offer_id),
        INDEX idx_music_order_item_order_v130 (order_id,id),
        INDEX idx_music_order_item_entitlement_v130 (entitlement_id,id),
        CONSTRAINT fk_music_order_item_order_v130 FOREIGN KEY (order_id) REFERENCES music_orders_v130(id) ON DELETE CASCADE,
        CONSTRAINT fk_music_order_item_offer_v130 FOREIGN KEY (offer_id) REFERENCES music_offers_v130(id) ON DELETE RESTRICT,
        CONSTRAINT fk_music_order_item_entitlement_v130 FOREIGN KEY (entitlement_id) REFERENCES music_entitlements_v120(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS music_order_events_v130 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        event_type VARCHAR(80) NOT NULL,
        actor_user_id BIGINT UNSIGNED NULL,
        metadata_json JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_music_order_event_v130 (order_id,created_at,id),
        CONSTRAINT fk_music_order_event_order_v130 FOREIGN KEY (order_id) REFERENCES music_orders_v130(id) ON DELETE RESTRICT,
        CONSTRAINT fk_music_order_event_actor_v130 FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS music_payment_events_v130 (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        provider VARCHAR(40) NOT NULL,
        provider_event_key VARCHAR(190) NOT NULL,
        provider_payment_ref VARCHAR(190) NOT NULL DEFAULT '',
        event_type VARCHAR(40) NOT NULL,
        amount_cents BIGINT UNSIGNED NOT NULL,
        currency CHAR(3) NOT NULL,
        payload_hash CHAR(64) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_music_payment_event_v130 (provider,provider_event_key),
        INDEX idx_music_payment_event_order_v130 (order_id,created_at,id),
        CONSTRAINT fk_music_payment_event_order_v130 FOREIGN KEY (order_id) REFERENCES music_orders_v130(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

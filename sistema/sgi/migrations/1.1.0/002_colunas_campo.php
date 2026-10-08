<?php
// Migração PHP: adiciona colunas de forma idempotente (MySQL 5.7 não suporta ADD COLUMN IF NOT EXISTS).
return function (Db $db) {
    $add = function (string $table, string $col, string $ddl) use ($db) {
        if (!$db->columnExists($table, $col)) {
            $db->exec("ALTER TABLE `$table` ADD COLUMN $ddl");
        }
    };
    $add('records', 'client_uuid', "client_uuid VARCHAR(64) NULL");
    $add('records', 'geo_lat', "geo_lat DECIMAL(10,7) NULL");
    $add('records', 'geo_lng', "geo_lng DECIMAL(10,7) NULL");
    $add('records', 'geo_accuracy', "geo_accuracy DECIMAL(10,2) NULL");
    $add('records', 'asset_id', "asset_id INT UNSIGNED NULL");
    $add('attachments', 'geo_lat', "geo_lat DECIMAL(10,7) NULL");
    $add('attachments', 'geo_lng', "geo_lng DECIMAL(10,7) NULL");
    $add('users', 'notify_email', "notify_email TINYINT(1) NOT NULL DEFAULT 1");
    $add('users', 'notify_push', "notify_push TINYINT(1) NOT NULL DEFAULT 1");
    if (!$db->indexExists('records', 'uq_rec_client_uuid')) {
        $db->exec("ALTER TABLE records ADD UNIQUE KEY uq_rec_client_uuid (org_id, client_uuid)");
    }
};

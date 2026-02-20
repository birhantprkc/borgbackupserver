<?php
/**
 * Migration 059: Drop MySQL catalog tables — catalog data now lives in ClickHouse.
 *
 * After this migration, the scheduler will auto-detect empty ClickHouse
 * catalogs and queue catalog_rebuild jobs to repopulate from borg archives.
 */

$pdo = $db->getPdo();

// Find and drop all file_catalog_* and catalog_dirs_* tables
$tables = $db->fetchAll(
    "SELECT TABLE_NAME FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND (TABLE_NAME LIKE 'file\\_catalog\\_%' OR TABLE_NAME LIKE 'catalog\\_dirs\\_%')"
);

$dropped = 0;
foreach ($tables as $row) {
    $tableName = $row['TABLE_NAME'];
    // Safety check: only drop tables matching the expected pattern
    if (preg_match('/^(file_catalog|catalog_dirs)_\d+(_rebuild)?$/', $tableName)) {
        $pdo->exec("DROP TABLE IF EXISTS `{$tableName}`");
        $dropped++;
        echo "  Dropped {$tableName}\n";
    }
}

if ($dropped > 0) {
    echo "  Dropped {$dropped} MySQL catalog table(s) — catalog data now in ClickHouse\n";
} else {
    echo "  No MySQL catalog tables found\n";
}

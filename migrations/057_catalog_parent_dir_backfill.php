<?php
/**
 * Add parent_dir column to per-agent catalog tables and backfill from path.
 * Replaces 056 which failed due to ensureTable() silently swallowing errors.
 */

$agents = $db->fetchAll('SELECT id FROM agents');
$pdo = $db->getPdo();

foreach ($agents as $a) {
    $agentId = (int) $a['id'];
    $table = "file_catalog_{$agentId}";

    // Check if table exists
    $exists = $db->fetchOne(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$table]
    );
    if (!$exists) continue;

    // Add parent_dir column if missing
    $col = $db->fetchOne(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'parent_dir'",
        [$table]
    );
    if (!$col) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN parent_dir VARCHAR(768) NOT NULL DEFAULT '' AFTER file_name");
        echo "  Agent {$agentId}: added parent_dir column\n";
    }

    // Add index if missing
    $idx = $db->fetchAll("SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_archive_parent'");
    if (empty($idx)) {
        $pdo->exec("ALTER TABLE `{$table}` ADD KEY `idx_archive_parent` (archive_id, parent_dir(200))");
        echo "  Agent {$agentId}: added idx_archive_parent index\n";
    }

    // Backfill parent_dir for rows where it's empty
    $count = $db->fetchOne("SELECT COUNT(*) AS cnt FROM `{$table}` WHERE parent_dir = ''");
    if ($count && (int) $count['cnt'] > 0) {
        $pdo->exec("UPDATE `{$table}` SET parent_dir =
            IF(path = CONCAT('/', file_name), '/',
               LEFT(path, LENGTH(path) - LENGTH(file_name) - 1))
            WHERE parent_dir = ''");
        echo "  Agent {$agentId}: backfilled parent_dir for " . number_format((int) $count['cnt']) . " rows\n";
    }
}
echo "  Done (" . count($agents) . " agent tables)\n";

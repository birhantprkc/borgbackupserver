<?php
/**
 * Add parent_dir column to per-agent catalog tables and backfill from path.
 * Uses CatalogImporter::ensureTable() to add the column and index,
 * then UPDATE to compute parent_dir = dirname(path) for existing rows.
 */

use BBS\Services\CatalogImporter;

$agents = $db->fetchAll('SELECT id FROM agents');
$pdo = $db->getPdo();

foreach ($agents as $a) {
    $agentId = (int) $a['id'];
    $table = "file_catalog_{$agentId}";

    // Add column + index if missing
    CatalogImporter::ensureTable($db, $agentId);

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

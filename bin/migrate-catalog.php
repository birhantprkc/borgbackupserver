#!/usr/bin/env php
<?php
/**
 * One-time migration: copy data from old normalized catalog tables
 * (file_paths + file_catalog) into flat per-agent tables (file_catalog_{agent_id}).
 *
 * Called by bbs-update-run. Idempotent — skips agents already migrated.
 * After all agents are migrated, drops the old tables.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use BBS\Core\Database;
use BBS\Services\CatalogImporter;

set_time_limit(0);
ini_set('memory_limit', '-1');

$db = Database::getInstance();
$pdo = $db->getPdo();

// Check if old tables exist
try {
    $pdo->query("SELECT 1 FROM file_paths LIMIT 1");
} catch (\Exception $e) {
    echo "Old catalog tables already removed. Nothing to migrate.\n";
    exit(0);
}

// Check if there's any data to migrate
$oldCount = (int) $db->fetchOne("SELECT COUNT(*) AS cnt FROM file_catalog")['cnt'];
if ($oldCount === 0) {
    echo "No data in old catalog tables. Dropping them.\n";
    $pdo->exec("DROP TABLE IF EXISTS file_catalog");
    $pdo->exec("DROP TABLE IF EXISTS file_paths");
    echo "Done.\n";
    exit(0);
}

// Get all agents that have file_paths entries
$agents = $db->fetchAll(
    "SELECT DISTINCT agent_id FROM file_paths ORDER BY agent_id"
);

echo "Migrating catalog data for " . count($agents) . " agent(s) ({$oldCount} total entries)...\n";

$totalMigrated = 0;
foreach ($agents as $a) {
    $agentId = (int) $a['agent_id'];
    $table = "file_catalog_{$agentId}";

    // Ensure per-agent table exists
    CatalogImporter::ensureTable($db, $agentId);

    // Check if already migrated (table has data)
    $existing = (int) $db->fetchOne("SELECT COUNT(*) AS cnt FROM `{$table}`")['cnt'];
    if ($existing > 0) {
        echo "  Agent {$agentId}: already has {$existing} rows, skipping.\n";
        continue;
    }

    // Count rows to migrate for this agent
    $agentCount = (int) $db->fetchOne(
        "SELECT COUNT(*) AS cnt FROM file_catalog fc
         JOIN file_paths fp ON fp.id = fc.file_path_id
         WHERE fp.agent_id = ?",
        [$agentId]
    )['cnt'];

    if ($agentCount === 0) {
        echo "  Agent {$agentId}: no catalog data, skipping.\n";
        continue;
    }

    echo "  Agent {$agentId}: migrating {$agentCount} entries...";

    // Migrate in one INSERT...SELECT (parent_dir = dirname of path)
    $pdo->exec("INSERT INTO `{$table}` (archive_id, path, file_name, parent_dir, file_size, status, mtime)
        SELECT fc.archive_id, fp.path, fp.file_name,
               IF(fp.path = CONCAT('/', fp.file_name), '/',
                  LEFT(fp.path, LENGTH(fp.path) - LENGTH(fp.file_name) - 1)),
               fc.file_size, fc.status, fc.mtime
        FROM file_catalog fc
        JOIN file_paths fp ON fp.id = fc.file_path_id
        WHERE fp.agent_id = {$agentId}");

    $migrated = (int) $db->fetchOne("SELECT COUNT(*) AS cnt FROM `{$table}`")['cnt'];
    echo " done ({$migrated} rows).\n";
    $totalMigrated += $migrated;
}

echo "Migration complete: {$totalMigrated} total entries migrated.\n";

// Drop old tables now that data is migrated
echo "Dropping old catalog tables...\n";
$pdo->exec("DROP TABLE IF EXISTS file_catalog");
$pdo->exec("DROP TABLE IF EXISTS file_paths");
echo "Done.\n";

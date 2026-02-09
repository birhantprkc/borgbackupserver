<?php
/**
 * Migration 058: Build catalog_dirs index tables from existing file_catalog data.
 *
 * Creates a catalog_dirs_{agent_id} table for each agent, populated from
 * the parent_dir column in file_catalog_{agent_id}. This enables instant
 * directory browsing via exact-match lookups instead of slow LIKE scans.
 */

use BBS\Services\CatalogImporter;

$pdo = $db->getPdo();

// Find all file_catalog_* tables
$tables = $db->fetchAll(
    "SELECT TABLE_NAME FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'file\_catalog\_%'"
);

foreach ($tables as $row) {
    $tableName = $row['TABLE_NAME'];
    if (!preg_match('/^file_catalog_(\d+)$/', $tableName, $m)) continue;
    $agentId = (int) $m[1];

    CatalogImporter::ensureDirsTable($db, $agentId);

    $dirsTable = "catalog_dirs_{$agentId}";

    // Get distinct archive IDs
    $archives = $db->fetchAll("SELECT DISTINCT archive_id FROM `{$tableName}`");

    foreach ($archives as $ar) {
        $archiveId = (int) $ar['archive_id'];

        // Skip if already populated
        $existing = $db->fetchOne(
            "SELECT 1 FROM `{$dirsTable}` WHERE archive_id = ? LIMIT 1",
            [$archiveId]
        );
        if ($existing) continue;

        // Build dir stats from file_catalog using GROUP BY parent_dir (exact indexed reads)
        $dirRows = $db->fetchAll("
            SELECT parent_dir,
                   COUNT(*) as file_count,
                   SUM(file_size) as total_size
            FROM `{$tableName}`
            WHERE archive_id = ? AND status != 'D'
            GROUP BY parent_dir
        ", [$archiveId]);

        // Collect all dirs including ancestors
        $allDirs = []; // dirPath => [file_count, total_size]
        foreach ($dirRows as $d) {
            $dirPath = $d['parent_dir'];
            if ($dirPath === '' || $dirPath === '/') {
                // Root-level files — don't create a dir entry for root itself,
                // but ensure ancestors are walked (none for root)
                continue;
            }
            if (!isset($allDirs[$dirPath])) {
                $allDirs[$dirPath] = [0, 0];
            }
            $allDirs[$dirPath][0] += (int) $d['file_count'];
            $allDirs[$dirPath][1] += (int) $d['total_size'];

            // Walk up ancestors
            $p = dirname($dirPath);
            while ($p !== '/' && $p !== '.' && !isset($allDirs[$p])) {
                $allDirs[$p] = [0, 0];
                $p = dirname($p);
            }
        }

        if (empty($allDirs)) continue;

        // Batch insert
        $values = [];
        $params = [];
        foreach ($allDirs as $dirPath => [$fc, $sz]) {
            $parent = dirname($dirPath);
            if ($parent === '.') $parent = '/';
            $name = basename($dirPath);
            $values[] = "(?, ?, ?, ?, ?, ?)";
            array_push($params, $archiveId, $dirPath, $parent, $name, $fc, $sz);

            // Insert in batches of 1000
            if (count($values) >= 1000) {
                $pdo->prepare("INSERT INTO `{$dirsTable}` (archive_id, dir_path, parent_dir, name, file_count, total_size) VALUES " . implode(',', $values))
                    ->execute($params);
                $values = [];
                $params = [];
            }
        }
        if (!empty($values)) {
            $pdo->prepare("INSERT INTO `{$dirsTable}` (archive_id, dir_path, parent_dir, name, file_count, total_size) VALUES " . implode(',', $values))
                ->execute($params);
        }

        echo "  Agent {$agentId}, archive {$archiveId}: " . number_format(count($allDirs)) . " dirs indexed\n";
    }
}

echo "  Done\n";

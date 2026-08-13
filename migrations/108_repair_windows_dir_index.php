<?php
/**
 * Migration 108: repair directory indexes with nothing at the browse root (#394)
 *
 * Building the directory index moved from PHP into ClickHouse in #391, and the
 * ancestor walk lost its first step. On a POSIX path that step is the empty
 * string before the leading slash and is meant to be skipped; on a Windows path
 * it is the drive itself. So every Windows archive catalogued since then has a
 * complete file catalog and a directory tree with no entry at the root — the
 * file browser opens, asks for the root, gets nothing, and shows an empty pane
 * on a backup that is perfectly intact.
 *
 * The catalog rows are fine, so this rebuilds the index from them rather than
 * asking anyone to re-run a catalog import. Only archives that are actually
 * missing a root are touched.
 */

use BBS\Core\ClickHouse;

try {
    $ch = ClickHouse::getInstance();
    if (!$ch->isAvailable()) {
        echo "  ClickHouse not reachable — skipping directory index repair.\n";
        echo "  Rebuild affected catalogs from the repository page if file browsing looks empty.\n";
        return;
    }

    $broken = $ch->fetchAll("
        SELECT agent_id, archive_id
        FROM file_catalog
        WHERE (agent_id, archive_id) NOT IN (
            SELECT agent_id, archive_id FROM catalog_dirs WHERE parent_dir = '/'
        )
        GROUP BY agent_id, archive_id
        ORDER BY agent_id, archive_id
    ");

    if (empty($broken)) {
        echo "  No archives need their directory index repaired.\n";
        return;
    }

    echo "  Repairing directory index for " . count($broken) . " archive(s)...\n";
    $fixed = 0;
    foreach ($broken as $row) {
        $agentId = (int) $row['agent_id'];
        $archiveId = (int) $row['archive_id'];
        try {
            $ch->rebuildDirIndex($agentId, $archiveId);
            $fixed++;
        } catch (\Throwable $e) {
            // One bad archive shouldn't stop the update. It can be repaired
            // later with Rebuild Catalog from the repository page.
            echo "    archive {$archiveId}: {$e->getMessage()}\n";
        }
    }
    echo "  Repaired {$fixed} of " . count($broken) . " archive(s).\n";
} catch (\Throwable $e) {
    echo "  Directory index repair skipped: {$e->getMessage()}\n";
}

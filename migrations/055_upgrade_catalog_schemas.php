<?php
/**
 * Upgrade per-agent catalog tables: TEXT→VARCHAR(768), InnoDB→MyISAM, fix indexes.
 * Uses CatalogImporter::ensureTable() which is idempotent.
 */

use BBS\Services\CatalogImporter;

$agents = $db->fetchAll('SELECT id FROM agents');
foreach ($agents as $a) {
    CatalogImporter::ensureTable($db, (int) $a['id']);
}
echo '  Upgraded catalog schemas for ' . count($agents) . " agent(s)\n";

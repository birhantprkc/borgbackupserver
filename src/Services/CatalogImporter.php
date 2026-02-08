<?php

namespace BBS\Services;

use BBS\Core\Database;

class CatalogImporter
{
    /**
     * Process a JSONL catalog file into the per-agent file_catalog_{agent_id} table.
     *
     * Converts JSONL → TSV in a single pass, then uses LOAD DATA LOCAL INFILE
     * to bulk-load directly into the flat per-agent table. No staging tables,
     * no JOINs, no dedup needed.
     *
     * @return int Number of catalog entries imported
     */
    public function processFile(Database $db, int $agentId, int $archiveId, string $filePath): int
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new \RuntimeException("Cannot open catalog file: {$filePath}");
        }

        $pdo = $db->getPdo();
        $table = "file_catalog_{$agentId}";

        self::ensureTable($db, $agentId);

        $tsvFile = sys_get_temp_dir() . "/catalog_{$agentId}_{$archiveId}_" . getmypid() . '.tsv';
        $tsvFh = fopen($tsvFile, 'w');
        if (!$tsvFh) {
            fclose($handle);
            throw new \RuntimeException("Cannot write temp file: {$tsvFile}");
        }

        try {
            $count = 0;

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) continue;

                $entry = json_decode($line, true);
                if (!$entry || empty($entry['path'])) continue;

                $path = str_replace(["\t", "\n", "\\"], ["\\t", "\\n", "\\\\"], $entry['path']);
                $name = str_replace(["\t", "\n", "\\"], ["\\t", "\\n", "\\\\"], basename($entry['path']));
                $status = substr($entry['status'] ?? 'U', 0, 1);
                $size = (int) ($entry['size'] ?? 0);
                $mtime = $entry['mtime'] ?? '\\N';

                fwrite($tsvFh, "{$archiveId}\t{$path}\t{$name}\t{$size}\t{$status}\t{$mtime}\n");
                $count++;
            }

            fclose($handle);
            $handle = null;
            fclose($tsvFh);
            $tsvFh = null;

            if ($count === 0) {
                return 0;
            }

            // Disable constraint checks for bulk load performance
            $pdo->exec("SET unique_checks=0, foreign_key_checks=0");

            $pdo->exec("LOAD DATA LOCAL INFILE " . $pdo->quote($tsvFile) . "
                INTO TABLE `{$table}`
                FIELDS TERMINATED BY '\\t' ESCAPED BY '\\\\'
                LINES TERMINATED BY '\\n'
                (archive_id, path, file_name, file_size, status, @vmtime)
                SET mtime = NULLIF(@vmtime, '\\\\N')");

            $pdo->exec("SET unique_checks=1, foreign_key_checks=1");

            return $count;
        } finally {
            if ($handle) fclose($handle);
            if ($tsvFh) fclose($tsvFh);
            @unlink($tsvFile);
            $pdo->exec("SET unique_checks=1, foreign_key_checks=1");
        }
    }

    /**
     * Ensure the per-agent catalog table exists. Safe to call multiple times.
     * Also drops legacy FK/indexes from older table versions for performance.
     */
    public static function ensureTable(Database $db, int $agentId): void
    {
        $pdo = $db->getPdo();
        $table = "file_catalog_{$agentId}";

        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$table}` (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            archive_id INT NOT NULL,
            path TEXT NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_size BIGINT DEFAULT 0,
            status CHAR(1) DEFAULT 'U',
            mtime DATETIME NULL,
            KEY idx_archive (archive_id)
        ) ENGINE=InnoDB");

        // Drop legacy FK constraint and file_name index if present (slow during bulk load)
        try {
            $constraints = $db->fetchAll(
                "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                [$table]
            );
            foreach ($constraints as $c) {
                $pdo->exec("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$c['CONSTRAINT_NAME']}`");
            }
        } catch (\Exception $e) { /* ignore */ }

        try {
            $indexes = $db->fetchAll("SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_file_name'");
            if (!empty($indexes)) {
                $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `idx_file_name`");
            }
        } catch (\Exception $e) { /* ignore */ }
    }
}

<?php

namespace BBS\Services;

use BBS\Core\Database;

class CatalogImporter
{
    private const SECURE_FILE_DIR = '/var/lib/mysql-files';

    /**
     * Process a JSONL catalog file into the per-agent file_catalog_{agent_id} table.
     *
     * Converts JSONL → TSV in a single pass, then uses LOAD DATA INFILE
     * (server-side read) for maximum speed into a MyISAM table.
     * Falls back to LOAD DATA LOCAL INFILE if the secure dir isn't writable.
     *
     * @param int|null $jobId Optional backup job ID for detailed log entries
     * @return int Number of catalog entries imported
     */
    public function processFile(Database $db, int $agentId, int $archiveId, string $filePath, ?int $jobId = null): int
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        $log = function (string $message) use ($db, $agentId, $jobId) {
            $data = ['agent_id' => $agentId, 'level' => 'info', 'message' => $message];
            if ($jobId) {
                $data['backup_job_id'] = $jobId;
            }
            try { $db->insert('server_log', $data); } catch (\Exception $e) { /* ignore */ }
        };

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new \RuntimeException("Cannot open catalog file: {$filePath}");
        }

        $pdo = $db->getPdo();
        $table = "file_catalog_{$agentId}";

        self::ensureTable($db, $agentId);

        // Write TSV to MySQL's secure_file_priv dir for server-side LOAD DATA.
        // Fall back to /tmp if not writable (uses LOAD DATA LOCAL instead).
        $useServerSide = is_dir(self::SECURE_FILE_DIR) && is_writable(self::SECURE_FILE_DIR);
        $tsvDir = $useServerSide ? self::SECURE_FILE_DIR : sys_get_temp_dir();
        $tsvFile = $tsvDir . "/catalog_{$agentId}_{$archiveId}_" . getmypid() . '.tsv';

        $tsvFh = fopen($tsvFile, 'w');
        if (!$tsvFh) {
            fclose($handle);
            throw new \RuntimeException("Cannot write temp file: {$tsvFile}");
        }

        try {
            $tsvStart = microtime(true);
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

            $tsvElapsed = round(microtime(true) - $tsvStart, 1);
            $tsvSize = round(filesize($tsvFile) / 1048576, 1);

            if ($count === 0) {
                return 0;
            }

            $loadSql = fn(string $cmd) => "{$cmd} " . $pdo->quote($tsvFile) . "
                INTO TABLE `{$table}`
                FIELDS TERMINATED BY '\\t' ESCAPED BY '\\\\'
                LINES TERMINATED BY '\\n'
                (archive_id, path, file_name, file_size, status, @vmtime)
                SET mtime = NULLIF(@vmtime, '\\\\N')";

            // Try server-side LOAD DATA first (fastest), fall back to LOCAL
            $loadMethod = $useServerSide ? 'server-side' : 'client-side (LOCAL)';
            $log("Catalog TSV generated: " . number_format($count) . " rows, {$tsvSize} MB in {$tsvElapsed}s — loading via {$loadMethod}");

            $loadStart = microtime(true);

            if ($useServerSide) {
                try {
                    $pdo->exec($loadSql('LOAD DATA INFILE'));
                } catch (\Exception $e) {
                    // FILE privilege missing or secure_file_priv issue — fall back to LOCAL
                    $loadMethod = 'client-side (LOCAL fallback)';
                    $log("Server-side LOAD DATA failed: " . $e->getMessage() . " — falling back to LOCAL");
                    $pdo->exec($loadSql('LOAD DATA LOCAL INFILE'));
                }
            } else {
                $pdo->exec($loadSql('LOAD DATA LOCAL INFILE'));
            }

            $loadElapsed = round(microtime(true) - $loadStart, 1);
            $log("Catalog MySQL load complete: {$loadElapsed}s ({$loadMethod} into {$table})");

            return $count;
        } finally {
            if ($handle) fclose($handle);
            if ($tsvFh) fclose($tsvFh);
            @unlink($tsvFile);
        }
    }

    /**
     * Ensure the per-agent catalog table exists as MyISAM.
     * Converts existing InnoDB tables and drops legacy FK/indexes.
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
        ) ENGINE=MyISAM");

        // Convert existing InnoDB tables to MyISAM (must drop FKs first)
        try {
            $row = $db->fetchOne(
                "SELECT ENGINE FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$table]
            );
            if ($row && strtolower($row['ENGINE']) !== 'myisam') {
                // Drop any FK constraints first (MyISAM doesn't support them)
                $constraints = $db->fetchAll(
                    "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                    [$table]
                );
                foreach ($constraints as $c) {
                    $pdo->exec("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$c['CONSTRAINT_NAME']}`");
                }

                // Drop file_name index if present (not needed, slows bulk loads)
                $indexes = $db->fetchAll("SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_file_name'");
                if (!empty($indexes)) {
                    $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `idx_file_name`");
                }

                // Convert to MyISAM
                $pdo->exec("ALTER TABLE `{$table}` ENGINE=MyISAM");
            }
        } catch (\Exception $e) { /* ignore — table will work either way */ }
    }
}

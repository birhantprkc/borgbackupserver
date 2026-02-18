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

        // Update job status_message so the UI shows import progress
        $updateStatus = function (string $msg) use ($db, $jobId) {
            if (!$jobId) return;
            try { $db->update('backup_jobs', ['status_message' => $msg], 'id = ?', [$jobId]); } catch (\Exception $e) { /* ignore */ }
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
            $escape = fn(string $s) => str_replace(["\t", "\n", "\\"], ["\\t", "\\n", "\\\\"], $s);

            // Track directory stats: dirPath => [file_count, total_size]
            $dirStats = [];

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) continue;

                $entry = json_decode($line, true);
                if (!$entry || empty($entry['path'])) continue;

                $rawPath = $entry['path'];
                $path = $escape($rawPath);
                $name = $escape(basename($rawPath));
                $rawParent = dirname($rawPath);
                $parentDir = $escape($rawParent);
                $status = substr($entry['status'] ?? 'U', 0, 1);
                $size = (int) ($entry['size'] ?? 0);
                $mtime = $entry['mtime'] ?? '\\N';

                fwrite($tsvFh, "{$archiveId}\t{$path}\t{$name}\t{$parentDir}\t{$size}\t{$status}\t{$mtime}\n");
                $count++;

                // Accumulate per-directory stats (use raw unescaped paths)
                if ($status !== 'D') {
                    if (!isset($dirStats[$rawParent])) {
                        $dirStats[$rawParent] = [0, 0];
                    }
                    $dirStats[$rawParent][0]++;
                    $dirStats[$rawParent][1] += $size;
                }
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
                (archive_id, path, file_name, parent_dir, file_size, status, @vmtime)
                SET mtime = NULLIF(@vmtime, '\\\\N')";

            // Try server-side LOAD DATA first (fastest), fall back to LOCAL
            $loadMethod = $useServerSide ? 'server-side' : 'client-side (LOCAL)';
            $log("Catalog TSV generated: " . number_format($count) . " rows, {$tsvSize} MB in {$tsvElapsed}s — loading via {$loadMethod}");
            $updateStatus("Importing " . number_format($count) . " catalog entries...");

            $loadStart = microtime(true);

            // CONCURRENT allows concurrent reads on MyISAM during the insert,
            // preventing table-level locks from blocking the entire server
            if ($useServerSide) {
                try {
                    $pdo->exec($loadSql('LOAD DATA CONCURRENT INFILE'));
                } catch (\Exception $e) {
                    // FILE privilege missing or secure_file_priv issue — fall back to LOCAL
                    $loadMethod = 'client-side (LOCAL fallback)';
                    $log("Server-side LOAD DATA failed: " . $e->getMessage() . " — falling back to LOCAL");
                    $pdo->exec($loadSql('LOAD DATA CONCURRENT LOCAL INFILE'));
                }
            } else {
                $pdo->exec($loadSql('LOAD DATA CONCURRENT LOCAL INFILE'));
            }

            $loadElapsed = round(microtime(true) - $loadStart, 1);
            $log("Catalog MySQL load complete: {$loadElapsed}s ({$loadMethod} into {$table})");
            $updateStatus("Building directory index...");

            // Build catalog_dirs table for fast directory browsing
            $this->buildDirIndex($db, $pdo, $agentId, $archiveId, $dirStats, $tsvDir, $useServerSide, $log);

            // Update cached catalog total for dashboard (avoids INFORMATION_SCHEMA on MyISAM)
            self::updateCachedTotal($db);

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
            path VARCHAR(768) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            parent_dir VARCHAR(768) NOT NULL DEFAULT '',
            file_size BIGINT DEFAULT 0,
            status CHAR(1) DEFAULT 'U',
            mtime DATETIME NULL,
            KEY idx_archive_parent (archive_id, parent_dir(200)),
            KEY idx_archive_path (archive_id, path(200))
        ) ENGINE=MyISAM");

        // Upgrade existing tables: convert InnoDB→MyISAM, TEXT→VARCHAR, fix indexes
        try {
            $row = $db->fetchOne(
                "SELECT ENGINE FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$table]
            );
            if (!$row) return;

            $needsAlter = false;
            $alterParts = [];

            // Drop any FK constraints (MyISAM doesn't support them)
            $constraints = $db->fetchAll(
                "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
                [$table]
            );
            foreach ($constraints as $c) {
                $alterParts[] = "DROP FOREIGN KEY `{$c['CONSTRAINT_NAME']}`";
                $needsAlter = true;
            }

            // Drop legacy idx_file_name if present
            $indexes = $db->fetchAll("SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_file_name'");
            if (!empty($indexes)) {
                $alterParts[] = "DROP INDEX `idx_file_name`";
                $needsAlter = true;
            }

            // Convert path from TEXT to VARCHAR(768) for indexing
            $col = $db->fetchOne(
                "SELECT DATA_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'path'",
                [$table]
            );
            if ($col && strtolower($col['DATA_TYPE']) === 'text') {
                $alterParts[] = "MODIFY COLUMN path VARCHAR(768) NOT NULL";
                $needsAlter = true;
            }

            // Replace idx_archive with idx_archive_path (composite index for tree queries)
            $archiveIdx = $db->fetchAll("SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_archive'");
            if (!empty($archiveIdx)) {
                $alterParts[] = "DROP INDEX `idx_archive`";
                $alterParts[] = "ADD KEY `idx_archive_path` (archive_id, path(200))";
                $needsAlter = true;
            } else {
                $archivePathIdx = $db->fetchAll("SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_archive_path'");
                if (empty($archivePathIdx)) {
                    $alterParts[] = "ADD KEY `idx_archive_path` (archive_id, path(200))";
                    $needsAlter = true;
                }
            }

            // Add parent_dir column if missing
            $parentCol = $db->fetchOne(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'parent_dir'",
                [$table]
            );
            if (!$parentCol) {
                $alterParts[] = "ADD COLUMN parent_dir VARCHAR(768) NOT NULL DEFAULT '' AFTER file_name";
                $alterParts[] = "ADD KEY `idx_archive_parent` (archive_id, parent_dir(200))";
                $needsAlter = true;
            } else {
                // Column exists — ensure index exists
                $parentIdx = $db->fetchAll("SHOW INDEX FROM `{$table}` WHERE Key_name = 'idx_archive_parent'");
                if (empty($parentIdx)) {
                    $alterParts[] = "ADD KEY `idx_archive_parent` (archive_id, parent_dir(200))";
                    $needsAlter = true;
                }
            }

            // Convert engine to MyISAM
            if (strtolower($row['ENGINE']) !== 'myisam') {
                $needsAlter = true;
            }

            if ($needsAlter) {
                $engine = strtolower($row['ENGINE']) !== 'myisam' ? ' ENGINE=MyISAM' : '';
                $sql = "ALTER TABLE `{$table}` " . implode(', ', $alterParts) . $engine;
                $pdo->exec($sql);
            }
        } catch (\Exception $e) { /* ignore — table will work either way */ }
    }

    /**
     * Ensure the per-agent catalog_dirs table exists.
     */
    public static function ensureDirsTable(Database $db, int $agentId): void
    {
        $pdo = $db->getPdo();
        $table = "catalog_dirs_{$agentId}";

        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$table}` (
            archive_id INT NOT NULL,
            dir_path VARCHAR(768) NOT NULL,
            parent_dir VARCHAR(768) NOT NULL,
            name VARCHAR(255) NOT NULL,
            file_count INT NOT NULL DEFAULT 0,
            total_size BIGINT NOT NULL DEFAULT 0,
            KEY idx_lookup (archive_id, parent_dir(200)),
            KEY idx_dir (archive_id, dir_path(200))
        ) ENGINE=MyISAM");
    }

    /**
     * Build the catalog_dirs index table from collected directory stats.
     * Uses LOAD DATA for speed, just like the main catalog import.
     */
    private function buildDirIndex(Database $db, \PDO $pdo, int $agentId, int $archiveId, array $dirStats, string $tsvDir, bool $useServerSide, callable $log): void
    {
        self::ensureDirsTable($db, $agentId);

        $dirsTable = "catalog_dirs_{$agentId}";

        // Remove old dir entries for this archive
        $pdo->exec("DELETE FROM `{$dirsTable}` WHERE archive_id = " . (int) $archiveId);

        if (empty($dirStats)) return;

        // Collect all directory paths (including ancestors) so the tree is complete.
        // dirStats has the leaf dirs with files; we need intermediate dirs too.
        $allDirs = []; // dirPath => [file_count, total_size]
        foreach ($dirStats as $dirPath => [$fc, $sz]) {
            // Add this dir
            if (!isset($allDirs[$dirPath])) {
                $allDirs[$dirPath] = [0, 0];
            }
            $allDirs[$dirPath][0] += $fc;
            $allDirs[$dirPath][1] += $sz;

            // Walk up ancestors to ensure they exist (no file counts for intermediates)
            $p = dirname($dirPath);
            while ($p !== '/' && $p !== '.' && !isset($allDirs[$p])) {
                $allDirs[$p] = [0, 0];
                $p = dirname($p);
            }
        }

        // Don't include root itself as a directory entry
        unset($allDirs['/']);

        // Write dirs TSV
        $escape = fn(string $s) => str_replace(["\t", "\n", "\\"], ["\\t", "\\n", "\\\\"], $s);
        $dirsTsv = $tsvDir . "/catalog_dirs_{$agentId}_{$archiveId}_" . getmypid() . '.tsv';
        $fh = fopen($dirsTsv, 'w');
        if (!$fh) return;

        foreach ($allDirs as $dirPath => [$fc, $sz]) {
            $parent = dirname($dirPath);
            if ($parent === '.') $parent = '/';
            $name = basename($dirPath);
            fwrite($fh, "{$archiveId}\t{$escape($dirPath)}\t{$escape($parent)}\t{$escape($name)}\t{$fc}\t{$sz}\n");
        }
        fclose($fh);

        $loadSql = fn(string $cmd) => "{$cmd} " . $pdo->quote($dirsTsv) . "
            INTO TABLE `{$dirsTable}`
            FIELDS TERMINATED BY '\\t' ESCAPED BY '\\\\'
            LINES TERMINATED BY '\\n'
            (archive_id, dir_path, parent_dir, name, file_count, total_size)";

        try {
            if ($useServerSide) {
                try {
                    $pdo->exec($loadSql('LOAD DATA CONCURRENT INFILE'));
                } catch (\Exception $e) {
                    $pdo->exec($loadSql('LOAD DATA CONCURRENT LOCAL INFILE'));
                }
            } else {
                $pdo->exec($loadSql('LOAD DATA CONCURRENT LOCAL INFILE'));
            }
            $log("Catalog dirs index: " . number_format(count($allDirs)) . " directories indexed");
        } catch (\Exception $e) {
            $log("Catalog dirs index failed: " . $e->getMessage());
        } finally {
            @unlink($dirsTsv);
        }
    }

    /**
     * Update the cached catalog_total_files in settings by summing row counts
     * from each per-agent catalog table. Uses COUNT(*) which is instant on
     * MyISAM (stored in metadata) — safe to call after LOAD DATA completes
     * since the table lock is already released.
     */
    public static function updateCachedTotal(Database $db): void
    {
        try {
            $agents = $db->fetchAll("SELECT id FROM agents");
            $total = 0;
            foreach ($agents as $a) {
                try {
                    $row = $db->fetchOne("SELECT COUNT(*) AS cnt FROM `file_catalog_{$a['id']}`");
                    $total += (int) ($row['cnt'] ?? 0);
                } catch (\Exception $e) { /* table may not exist */ }
            }
            $db->getPdo()->exec(
                "INSERT INTO settings (`key`, `value`) VALUES ('catalog_total_files', '{$total}')
                 ON DUPLICATE KEY UPDATE `value` = '{$total}'"
            );
        } catch (\Exception $e) { /* ignore */ }
    }
}

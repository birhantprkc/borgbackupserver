<?php

namespace BBS\Services;

use BBS\Core\Database;

class CatalogImporter
{
    /**
     * Process a JSONL catalog file from disk into file_paths + file_catalog tables.
     *
     * Uses LOAD DATA LOCAL INFILE with a staging table approach:
     *   1. Convert JSONL → TSV (paths deduped via PHP hash set)
     *   2. LOAD DATA LOCAL INFILE into temp staging table (fast, no indexes to maintain)
     *   3. INSERT only NEW paths into file_paths (LEFT JOIN to skip existing)
     *   4. INSERT IGNORE INTO file_catalog via staging JOIN file_paths
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
        $suffix = $agentId . '_' . $archiveId . '_' . getmypid();
        $stagingTsv = sys_get_temp_dir() . "/catalog_staging_{$suffix}.tsv";
        $pathsTsv = sys_get_temp_dir() . "/catalog_paths_{$suffix}.tsv";

        try {
            // Pass 1: Convert JSONL → two TSV files
            $stagingFh = fopen($stagingTsv, 'w');
            $pathsFh = fopen($pathsTsv, 'w');
            if (!$stagingFh || !$pathsFh) {
                throw new \RuntimeException("Cannot write temp files");
            }

            $seenPaths = [];
            $totalLines = 0;

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) continue;

                $entry = json_decode($line, true);
                if (!$entry || empty($entry['path'])) continue;

                $path = $entry['path'];
                $pathHash = hash('sha256', $agentId . ':' . $path);
                $status = substr($entry['status'] ?? 'U', 0, 1);
                $size = (int) ($entry['size'] ?? 0);
                $mtime = $entry['mtime'] ?? '\\N';

                // Paths TSV (deduped): agent_id \t path \t file_name \t path_hash
                if (!isset($seenPaths[$pathHash])) {
                    $seenPaths[$pathHash] = true;
                    $escapedPath = str_replace(["\t", "\n", "\\"], ["\\t", "\\n", "\\\\"], $path);
                    $escapedName = str_replace(["\t", "\n", "\\"], ["\\t", "\\n", "\\\\"], basename($path));
                    fwrite($pathsFh, "{$agentId}\t{$escapedPath}\t{$escapedName}\t{$pathHash}\n");
                }

                // Staging TSV: path_hash \t archive_id \t size \t status \t mtime
                fwrite($stagingFh, "{$pathHash}\t{$archiveId}\t{$size}\t{$status}\t{$mtime}\n");
                $totalLines++;
            }

            fclose($stagingFh);
            fclose($pathsFh);
            fclose($handle);
            $handle = null;

            if ($totalLines === 0) {
                return 0;
            }

            $seenPaths = []; // free memory

            // Step 2: Create staging table and bulk load catalog entries
            $pdo->exec("CREATE TEMPORARY TABLE _catalog_staging (
                path_hash CHAR(64) NOT NULL,
                archive_id INT NOT NULL,
                file_size BIGINT DEFAULT 0,
                status CHAR(1) DEFAULT 'U',
                mtime VARCHAR(19) NULL,
                KEY (path_hash)
            ) ENGINE=InnoDB");

            $pdo->exec("LOAD DATA LOCAL INFILE " . $pdo->quote($stagingTsv) . "
                INTO TABLE _catalog_staging
                FIELDS TERMINATED BY '\\t'
                LINES TERMINATED BY '\\n'
                (path_hash, archive_id, file_size, status, @vmtime)
                SET mtime = NULLIF(@vmtime, '\\\\N')");

            // Step 3: Load unique paths into a temp table, then insert only NEW ones
            $pdo->exec("CREATE TEMPORARY TABLE _new_paths (
                agent_id INT NOT NULL,
                path TEXT NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                path_hash CHAR(64) NOT NULL,
                UNIQUE KEY (path_hash)
            ) ENGINE=InnoDB");

            $pdo->exec("LOAD DATA LOCAL INFILE " . $pdo->quote($pathsTsv) . "
                INTO TABLE _new_paths
                FIELDS TERMINATED BY '\\t' ESCAPED BY '\\\\'
                LINES TERMINATED BY '\\n'
                (agent_id, path, file_name, path_hash)");

            // Only insert paths that don't already exist in file_paths
            $pdo->exec("INSERT INTO file_paths (agent_id, path, file_name, path_hash)
                SELECT np.agent_id, np.path, np.file_name, np.path_hash
                FROM _new_paths np
                LEFT JOIN file_paths fp ON fp.path_hash = np.path_hash
                WHERE fp.id IS NULL");

            $pdo->exec("DROP TEMPORARY TABLE _new_paths");

            // Step 4: Join staging with file_paths and insert into file_catalog
            $pdo->exec("INSERT IGNORE INTO file_catalog (archive_id, file_path_id, file_size, status, mtime)
                SELECT s.archive_id, fp.id, s.file_size, s.status, s.mtime
                FROM _catalog_staging s
                INNER JOIN file_paths fp ON fp.path_hash = s.path_hash");

            $pdo->exec("DROP TEMPORARY TABLE IF EXISTS _catalog_staging");

            return $totalLines;
        } finally {
            if ($handle) fclose($handle);
            @unlink($stagingTsv);
            @unlink($pathsTsv);
        }
    }
}

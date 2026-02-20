<?php

namespace BBS\Services;

use BBS\Core\ClickHouse;
use BBS\Core\Database;

class CatalogImporter
{
    /**
     * Process a JSONL catalog file into ClickHouse file_catalog table.
     *
     * Converts JSONL → TSV in a single pass, then bulk-uploads via
     * ClickHouse HTTP interface for maximum speed.
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

        $ch = ClickHouse::getInstance();
        $tsvFile = sys_get_temp_dir() . "/catalog_{$agentId}_{$archiveId}_" . getmypid() . '.tsv';

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

                fwrite($tsvFh, "{$agentId}\t{$archiveId}\t{$path}\t{$name}\t{$parentDir}\t{$size}\t{$status}\t{$mtime}\n");
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

            $log("Catalog TSV generated: " . number_format($count) . " rows, {$tsvSize} MB in {$tsvElapsed}s — loading into ClickHouse");
            $updateStatus("Importing " . number_format($count) . " catalog entries...");

            $loadStart = microtime(true);

            $ch->insertTsv('file_catalog', $tsvFile, [
                'agent_id', 'archive_id', 'path', 'file_name', 'parent_dir', 'file_size', 'status', 'mtime'
            ]);

            $loadElapsed = round(microtime(true) - $loadStart, 1);
            $log("Catalog ClickHouse load complete: {$loadElapsed}s");
            $updateStatus("Building directory index...");

            // Build catalog_dirs table for fast directory browsing
            $this->buildDirIndex($ch, $agentId, $archiveId, $dirStats, $log);

            // Update cached catalog total for dashboard
            self::updateCachedTotal($db);

            return $count;
        } finally {
            if ($handle) fclose($handle);
            if ($tsvFh) fclose($tsvFh);
            @unlink($tsvFile);
        }
    }

    /**
     * Build the catalog_dirs index table from collected directory stats.
     * Uses TSV upload to ClickHouse for speed.
     */
    private function buildDirIndex(ClickHouse $ch, int $agentId, int $archiveId, array $dirStats, callable $log): void
    {
        // Remove old dir entries for this archive
        try {
            $ch->exec("ALTER TABLE catalog_dirs DELETE WHERE agent_id = {$agentId} AND archive_id = {$archiveId} SETTINGS mutations_sync = 1");
        } catch (\Exception $e) { /* ignore */ }

        if (empty($dirStats)) return;

        // Collect all directory paths (including ancestors) so the tree is complete.
        $allDirs = []; // dirPath => [file_count, total_size]
        foreach ($dirStats as $dirPath => [$fc, $sz]) {
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
        $dirsTsv = sys_get_temp_dir() . "/catalog_dirs_{$agentId}_{$archiveId}_" . getmypid() . '.tsv';
        $fh = fopen($dirsTsv, 'w');
        if (!$fh) return;

        foreach ($allDirs as $dirPath => [$fc, $sz]) {
            $parent = dirname($dirPath);
            if ($parent === '.') $parent = '/';
            $name = basename($dirPath);
            fwrite($fh, "{$agentId}\t{$archiveId}\t{$escape($dirPath)}\t{$escape($parent)}\t{$escape($name)}\t{$fc}\t{$sz}\n");
        }
        fclose($fh);

        try {
            $ch->insertTsv('catalog_dirs', $dirsTsv, [
                'agent_id', 'archive_id', 'dir_path', 'parent_dir', 'name', 'file_count', 'total_size'
            ]);
            $log("Catalog dirs index: " . number_format(count($allDirs)) . " directories indexed");
        } catch (\Exception $e) {
            $log("Catalog dirs index failed: " . $e->getMessage());
        } finally {
            @unlink($dirsTsv);
        }
    }

    /**
     * Update the cached catalog_total_files in settings from ClickHouse.
     */
    public static function updateCachedTotal(Database $db): void
    {
        try {
            $ch = ClickHouse::getInstance();
            $row = $ch->fetchOne("SELECT count() as cnt FROM file_catalog");
            $total = (int) ($row['cnt'] ?? 0);
            $db->getPdo()->exec(
                "INSERT INTO settings (`key`, `value`) VALUES ('catalog_total_files', '{$total}')
                 ON DUPLICATE KEY UPDATE `value` = '{$total}'"
            );
        } catch (\Exception $e) { /* ignore */ }
    }
}

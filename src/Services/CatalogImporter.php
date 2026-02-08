<?php

namespace BBS\Services;

use BBS\Core\Database;

class CatalogImporter
{
    /**
     * Process a JSONL catalog file from disk into file_paths + file_catalog tables.
     *
     * Reads the file line by line in batches to keep memory constant.
     * Uses the same 3-step INSERT pattern as AgentApiController::catalog():
     *   1. INSERT IGNORE into file_paths (path dedup via path_hash)
     *   2. SELECT ids via path_hash IN (...)
     *   3. INSERT into file_catalog
     *
     * @return int Number of catalog entries imported
     */
    public function processFile(Database $db, int $agentId, int $archiveId, string $filePath): int
    {
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new \RuntimeException("Cannot open catalog file: {$filePath}");
        }

        $pdo = $db->getPdo();
        $batchSize = 5000;
        $batch = [];
        $totalImported = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) continue;

                $entry = json_decode($line, true);
                if (!$entry || empty($entry['path'])) continue;

                $batch[] = $entry;

                if (count($batch) >= $batchSize) {
                    $totalImported += $this->insertBatch($db, $pdo, $agentId, $archiveId, $batch);
                    $batch = [];
                }
            }

            // Final partial batch
            if (!empty($batch)) {
                $totalImported += $this->insertBatch($db, $pdo, $agentId, $archiveId, $batch);
            }
        } finally {
            fclose($handle);
        }

        return $totalImported;
    }

    /**
     * Insert a batch of catalog entries into file_paths + file_catalog.
     */
    private function insertBatch(Database $db, \PDO $pdo, int $agentId, int $archiveId, array $files): int
    {
        // Build path data with hashes for fast unique lookups
        $paths = [];
        foreach ($files as $file) {
            $path = $file['path'] ?? '';
            if (empty($path) || isset($paths[$path])) continue;
            $paths[$path] = hash('sha256', $agentId . ':' . $path);
        }

        if (empty($paths)) {
            return 0;
        }

        $pdo->beginTransaction();

        try {
            // Step 1: Upsert unique paths into file_paths using path_hash
            $pathPlaceholders = [];
            $pathValues = [];
            foreach ($paths as $path => $pathHash) {
                $pathPlaceholders[] = '(?, ?, ?, ?)';
                $pathValues[] = $agentId;
                $pathValues[] = $path;
                $pathValues[] = basename($path);
                $pathValues[] = $pathHash;
            }

            $sql = "INSERT IGNORE INTO file_paths (agent_id, path, file_name, path_hash) VALUES "
                 . implode(', ', $pathPlaceholders);
            $db->query($sql, $pathValues);

            // Step 2: Fetch IDs using path_hash (fast fixed-length index lookup)
            $hashValues = array_values($paths);
            $inPlaceholders = implode(',', array_fill(0, count($hashValues), '?'));
            $rows = $db->fetchAll(
                "SELECT id, path FROM file_paths WHERE path_hash IN ({$inPlaceholders})",
                $hashValues
            );
            $pathIdMap = [];
            foreach ($rows as $row) {
                $pathIdMap[$row['path']] = $row['id'];
            }

            // Step 3: Insert into file_catalog junction table
            $catalogPlaceholders = [];
            $catalogValues = [];
            foreach ($files as $file) {
                $path = $file['path'] ?? '';
                if (empty($path) || !isset($pathIdMap[$path])) continue;

                $catalogPlaceholders[] = '(?, ?, ?, ?, ?)';
                $catalogValues[] = $archiveId;
                $catalogValues[] = $pathIdMap[$path];
                $catalogValues[] = (int) ($file['size'] ?? 0);
                $catalogValues[] = $file['status'] ?? 'U';
                $catalogValues[] = $file['mtime'] ?? null;
            }

            $inserted = 0;
            if (!empty($catalogPlaceholders)) {
                $sql = "INSERT INTO file_catalog (archive_id, file_path_id, file_size, status, mtime) VALUES "
                     . implode(', ', $catalogPlaceholders);
                $db->query($sql, $catalogValues);
                $inserted = count($catalogPlaceholders);
            }

            $pdo->commit();
            return $inserted;
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

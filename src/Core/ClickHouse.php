<?php

namespace BBS\Core;

class ClickHouse
{
    private static ?self $instance = null;
    private string $baseUrl;
    private string $database;
    /** Whether this server knows query_plan_optimize_lazy_materialization (null = undetected). */
    private ?bool $lazyMatSettingSupported = null;

    private function __construct()
    {
        $host = Config::get('CLICKHOUSE_HOST', 'localhost');
        $port = Config::get('CLICKHOUSE_PORT', '8123');
        $this->database = Config::get('CLICKHOUSE_DB', 'bbs');
        $this->baseUrl = "http://{$host}:{$port}";
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Execute a query (DDL, INSERT, ALTER DELETE, etc.)
     */
    public function exec(string $sql): string
    {
        return $this->request($sql);
    }

    /**
     * Execute a SELECT query, return rows as associative arrays.
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $sql = $this->bindParams($sql, $params);
        $response = $this->request($sql . ' FORMAT JSONEachRow');
        if (trim($response) === '') return [];

        $rows = [];
        foreach (explode("\n", trim($response)) as $line) {
            if ($line !== '') {
                $rows[] = json_decode($line, true);
            }
        }
        return $rows;
    }

    /**
     * fetchAll for `ORDER BY <non-key-col> LIMIT n` queries against the file
     * catalog, with the ClickHouse 26.5 lazy-materialization top-N bug worked
     * around (#301).
     *
     * CH 26.5's `__topKFilter` optimization (query_plan_optimize_lazy_
     * materialization) mis-maps columns when the planner picks the lazy plan —
     * which it does under memory pressure on larger archives — feeding a String
     * column into a UInt64 filter and throwing `Code: 53 TYPE_MISMATCH`. That
     * blanks the "Largest Files" panel and the catalog file list while the
     * streaming GROUP BY queries (File Changes) keep working. Disabling lazy
     * materialization for the query restores the correct plan.
     *
     * Older ClickHouse builds predate the setting (and the bug); if the server
     * rejects it we cache that and fall back to a plain fetchAll so we don't
     * pay a failed round-trip on every call.
     */
    public function fetchAllOrdered(string $sql, array $params = []): array
    {
        if ($this->lazyMatSettingSupported === false) {
            return $this->fetchAll($sql, $params);
        }
        try {
            $rows = $this->fetchAll($sql . ' SETTINGS query_plan_optimize_lazy_materialization = 0', $params);
            $this->lazyMatSettingSupported = true;
            return $rows;
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'UNKNOWN_SETTING') !== false) {
                $this->lazyMatSettingSupported = false;
                return $this->fetchAll($sql, $params);
            }
            throw $e;
        }
    }

    /**
     * Execute a SELECT query, return first row or null.
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = $this->fetchAll($sql . ' LIMIT 1', $params);
        return $rows[0] ?? null;
    }

    /**
     * Bulk insert TSV data by streaming from a file.
     */
    public function insertTsv(string $table, string $tsvFilePath, array $columns): void
    {
        $cols = implode(', ', $columns);
        $sql = "INSERT INTO {$table} ({$cols}) FORMAT TabSeparated";

        $fileSize = filesize($tsvFilePath);
        $fh = fopen($tsvFilePath, 'r');
        if ($fh === false) {
            throw new \RuntimeException("Cannot read TSV file: {$tsvFilePath}");
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . '/?database=' . urlencode($this->database)
                         . '&query=' . urlencode($sql),
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/octet-stream',
                'Content-Length: ' . $fileSize,
            ],
            CURLOPT_READFUNCTION => function ($ch, $fh_inner, $length) use ($fh) {
                return fread($fh, $length);
            },
            CURLOPT_TIMEOUT => 600,
        ]);
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($error) {
            throw new \RuntimeException("ClickHouse TSV upload failed: {$error}");
        }
        if ($code !== 200) {
            throw new \RuntimeException("ClickHouse TSV insert failed ({$code}): {$response}");
        }
    }

    /**
     * Rebuild the catalog_dirs index for one archive, entirely inside
     * ClickHouse.
     *
     * This used to be done in PHP: accumulate a dirPath => [count, size] map
     * while streaming borg's output, copy it into a second map that also holds
     * every ancestor directory, then write both out as TSV. Both maps are
     * proportional to the number of directories in the archive, and both are
     * alive at once. A real 2.6M-file archive has 720k directories and needed
     * ~850MB peak — against a 128MB memory_limit, which is what Docker ships.
     * The result was an "Allowed memory size exhausted" fatal partway through:
     * borg vanished mid-run and the job simply stopped (#391).
     *
     * The aggregation and the ancestor expansion are both things a database is
     * good at, so they happen there and no rows travel through PHP at all.
     * Memory is constant regardless of archive size.
     *
     * Semantics match the previous implementation exactly: a directory holding
     * files carries their count and total size, a directory that only exists as
     * an ancestor carries zeros, and the root itself is not indexed.
     */
    public function rebuildDirIndex(int $agentId, int $archiveId): void
    {
        $this->exec(
            "ALTER TABLE catalog_dirs DELETE
             WHERE agent_id = {$agentId} AND archive_id = {$archiveId}
             SETTINGS mutations_sync = 1"
        );

        $this->exec("
            INSERT INTO catalog_dirs
                (agent_id, archive_id, dir_path, parent_dir, name, file_count, total_size)
            SELECT
                {$agentId} AS agent_id,
                {$archiveId} AS archive_id,
                dir_path,
                if(par = '', '/', par) AS parent_dir,
                arrayElement(sp, -1) AS name,
                sum(fc) AS file_count,
                sum(sz) AS total_size
            FROM (
                SELECT dir_path, fc, sz,
                       splitByChar('/', dir_path) AS sp,
                       arrayStringConcat(arraySlice(sp, 1, length(sp) - 1), '/') AS par
                FROM (
                    -- directories that directly hold files, with their totals
                    SELECT parent_dir AS dir_path, count() AS fc, sum(file_size) AS sz
                    FROM file_catalog
                    WHERE agent_id = {$agentId} AND archive_id = {$archiveId} AND status != 'D'
                    GROUP BY parent_dir

                    UNION ALL

                    -- every ancestor of those, contributing nothing of its own,
                    -- so the tree has no gaps when it is browsed.
                    --
                    -- The range starts at 1, not 2. A POSIX path splits with a
                    -- leading empty element ('/home/x' -> ['', 'home', 'x']), so
                    -- i=1 yields '' and is dropped by the filter below. A Windows
                    -- path has no such element ('C/Users/x' -> ['C', 'Users', 'x']),
                    -- so skipping i=1 skipped the drive itself — leaving the tree
                    -- with no entry at the browse root and a file browser that
                    -- showed nothing at all (#394).
                    SELECT anc AS dir_path, 0 AS fc, 0 AS sz
                    FROM (
                        SELECT arrayJoin(arrayMap(
                                   i -> arrayStringConcat(arraySlice(parts, 1, i), '/'),
                                   range(1, length(parts))
                               )) AS anc
                        FROM (
                            SELECT splitByChar('/', parent_dir) AS parts
                            FROM file_catalog
                            WHERE agent_id = {$agentId} AND archive_id = {$archiveId}
                              AND status != 'D'
                            GROUP BY parent_dir
                        )
                    )
                )
                WHERE dir_path != '' AND dir_path != '/'
            )
            GROUP BY dir_path, par, sp
        ");
    }

    /**
     * Check if ClickHouse is reachable.
     */
    public function isAvailable(): bool
    {
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->baseUrl . '/ping',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);
            $response = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Bind positional ? parameters into SQL with proper escaping.
     */
    private function bindParams(string $sql, array $params): string
    {
        if (empty($params)) return $sql;

        $i = 0;
        return preg_replace_callback('/\?/', function () use ($params, &$i) {
            $val = $params[$i++] ?? null;
            if (is_null($val)) return 'NULL';
            if (is_int($val) || is_float($val)) return (string) $val;
            // Escape for ClickHouse: single quotes, backslashes
            $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $val);
            return "'{$escaped}'";
        }, $sql);
    }

    /**
     * Core HTTP request to ClickHouse.
     */
    private function request(string $sql): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . '/?database=' . urlencode($this->database),
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $sql,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("ClickHouse connection failed: {$error}");
        }
        if ($httpCode !== 200) {
            throw new \RuntimeException("ClickHouse error ({$httpCode}): {$response}");
        }
        return $response;
    }
}

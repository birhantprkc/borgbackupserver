<?php

namespace BBS\Core;

class ClickHouse
{
    private static ?self $instance = null;
    private string $baseUrl;
    private string $database;

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

        $fh = fopen($tsvFilePath, 'r');
        if (!$fh) {
            throw new \RuntimeException("Cannot open TSV file: {$tsvFilePath}");
        }

        $fileSize = filesize($tsvFilePath);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . '/?database=' . urlencode($this->database)
                         . '&query=' . urlencode($sql),
            CURLOPT_POST => true,
            CURLOPT_INFILE => $fh,
            CURLOPT_INFILESIZE => $fileSize,
            CURLOPT_UPLOAD => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream'],
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

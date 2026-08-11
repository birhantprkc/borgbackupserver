<?php

namespace BBS\Core;

class Migrator
{
    private Database $db;
    private string $migrationsPath;

    /** Statements skipped because what they create already existed. */
    public array $skipped = [];

    /** Migrations that genuinely failed. These are NOT recorded as executed. */
    public array $errors = [];

    /**
     * MySQL errors that mean "this change is already in place".
     *
     * A migration re-running over a database that already has the change —
     * from schema.sql on a fresh install, from manual setup, or from a
     * previous partial run — is not a failure, so these are tolerated per
     * statement and the migration still counts as applied.
     *
     * Anything not in this list is a real failure and is treated as one.
     */
    private const ALREADY_APPLIED = [
        1050, // table already exists
        1060, // duplicate column name
        1061, // duplicate key name
        1022, // duplicate key
        1826, // duplicate foreign key constraint name
        1091, // can't DROP; check that column/key exists
        1359, // trigger already exists
        1517, // duplicate partition name
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->migrationsPath = dirname(__DIR__, 2) . '/migrations';
        $this->ensureMigrationsTable();
    }

    private function ensureMigrationsTable(): void
    {
        $this->db->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(255) NOT NULL UNIQUE,
                executed_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    /**
     * Split a migration file into individual statements.
     *
     * Statements are run one at a time rather than handing the whole file to
     * PDO::exec(). With one call, a failure part-way through leaves the earlier
     * statements applied and the later ones not — and nothing records which.
     * Per-statement execution means a benign "already exists" can be stepped
     * over while a real error still stops the file.
     *
     * Quotes, backticks and comments are tracked so a semicolon inside any of
     * them isn't mistaken for a statement boundary.
     */
    public static function splitStatements(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $inSingle = $inDouble = $inBacktick = $inLineComment = $inBlockComment = false;

        for ($i = 0; $i < $len; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                    $current .= $char;
                }
                continue;
            }
            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $i++;
                }
                continue;
            }

            if (!$inSingle && !$inDouble && !$inBacktick) {
                // `-- ` and `#` start a line comment; `/*` a block comment
                if (($char === '-' && $next === '-') || $char === '#') {
                    $inLineComment = true;
                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    $i++;
                    continue;
                }
                if ($char === ';') {
                    if (trim($current) !== '') {
                        $statements[] = trim($current);
                    }
                    $current = '';
                    continue;
                }
            }

            // Quote tracking. A backslash escapes the next character inside
            // single/double quotes, so it is consumed with it.
            if ($char === '\\' && ($inSingle || $inDouble)) {
                $current .= $char;
                if ($next !== '') {
                    $current .= $next;
                    $i++;
                }
                continue;
            }
            if ($char === "'" && !$inDouble && !$inBacktick)  $inSingle = !$inSingle;
            elseif ($char === '"' && !$inSingle && !$inBacktick) $inDouble = !$inDouble;
            elseif ($char === '`' && !$inSingle && !$inDouble)   $inBacktick = !$inBacktick;

            $current .= $char;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }
        return $statements;
    }

    public function run(): array
    {
        $sqlFiles = glob($this->migrationsPath . '/*.sql');
        $phpFiles = glob($this->migrationsPath . '/*.php');
        $files = array_merge($sqlFiles ?: [], $phpFiles ?: []);
        sort($files);

        $executed = array_column(
            $this->db->fetchAll("SELECT filename FROM migrations"),
            'filename'
        );

        $ran = [];
        $this->errors = [];
        $this->skipped = [];

        foreach ($files as $file) {
            $filename = basename($file);
            if (in_array($filename, $executed)) {
                continue;
            }

            try {
                if (str_ends_with($file, '.php')) {
                    $db = $this->db;
                    require $file;
                } else {
                    $this->runSqlFile($file, $filename);
                }
            } catch (\Exception $e) {
                // A real failure. The migration is deliberately NOT recorded,
                // so it runs again next time rather than being silently
                // skipped forever with the change half-applied. Statements
                // that already succeeded are re-run, which is safe: they fail
                // with "already exists" and are stepped over.
                $this->errors[] = $filename . ': ' . $e->getMessage();
                continue;
            }

            $this->db->insert('migrations', ['filename' => $filename]);
            $ran[] = $filename;
        }

        return $ran;
    }

    /**
     * Run one .sql file statement by statement.
     *
     * Statements whose object already exists are recorded and stepped over.
     * Anything else throws, which leaves the migration unrecorded.
     */
    private function runSqlFile(string $file, string $filename): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new \RuntimeException("Cannot read migration file");
        }

        $pdo = $this->db->getPdo();
        foreach (self::splitStatements($sql) as $index => $statement) {
            try {
                $pdo->exec($statement);
            } catch (\PDOException $e) {
                $code = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
                if (in_array($code, self::ALREADY_APPLIED, true)) {
                    $this->skipped[] = sprintf(
                        '%s statement %d: %s',
                        $filename,
                        $index + 1,
                        $this->firstLine($e->getMessage())
                    );
                    continue;
                }
                throw new \RuntimeException(sprintf(
                    'statement %d failed (%s) — %s | SQL: %s',
                    $index + 1,
                    $code,
                    $this->firstLine($e->getMessage()),
                    $this->firstLine($statement)
                ), 0, $e);
            }
        }
    }

    private function firstLine(string $text): string
    {
        $line = trim(strtok($text, "\n") ?: '');
        return strlen($line) > 200 ? substr($line, 0, 200) . '…' : $line;
    }
}

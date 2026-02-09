<?php

namespace BBS\Core;

class Migrator
{
    private Database $db;
    private string $migrationsPath;
    public array $errors = [];

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
        $errors = [];
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
                    $sql = file_get_contents($file);
                    $this->db->getPdo()->exec($sql);
                }
                $this->db->insert('migrations', ['filename' => $filename]);
                $ran[] = $filename;
            } catch (\Exception $e) {
                // Record the migration as executed so it doesn't block future runs
                // Common case: column/table already exists from manual setup or schema.sql
                $this->db->insert('migrations', ['filename' => $filename]);
                $errors[] = $filename . ': ' . $e->getMessage();
            }
        }

        $this->errors = $errors;
        return $ran;
    }
}

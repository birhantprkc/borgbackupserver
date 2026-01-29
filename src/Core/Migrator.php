<?php

namespace BBS\Core;

class Migrator
{
    private Database $db;
    private string $migrationsPath;

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
        $files = glob($this->migrationsPath . '/*.sql');
        sort($files);

        $executed = array_column(
            $this->db->fetchAll("SELECT filename FROM migrations"),
            'filename'
        );

        $ran = [];
        foreach ($files as $file) {
            $filename = basename($file);
            if (in_array($filename, $executed)) {
                continue;
            }

            $sql = file_get_contents($file);
            $this->db->getPdo()->exec($sql);
            $this->db->insert('migrations', ['filename' => $filename]);
            $ran[] = $filename;
        }

        return $ran;
    }
}

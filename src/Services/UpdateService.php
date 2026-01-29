<?php

namespace BBS\Services;

use BBS\Core\Database;
use BBS\Core\Migrator;

class UpdateService
{
    private Database $db;
    private string $projectRoot;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->projectRoot = dirname(__DIR__, 2);
    }

    public function getCurrentVersion(): string
    {
        $file = $this->projectRoot . '/VERSION';
        if (!file_exists($file)) return '0.0.0';
        return trim(file_get_contents($file));
    }

    public function getLatestRelease(): array
    {
        return [
            'version' => $this->getSetting('latest_version', ''),
            'notes' => $this->getSetting('latest_release_notes', ''),
            'url' => $this->getSetting('latest_release_url', ''),
            'checked_at' => $this->getSetting('last_update_check', ''),
        ];
    }

    public function isUpdateAvailable(): bool
    {
        $latest = $this->getSetting('latest_version', '');
        if (empty($latest)) return false;
        return version_compare($latest, $this->getCurrentVersion(), '>');
    }

    public function checkForUpdate(): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: BorgBackupServer/" . $this->getCurrentVersion() . "\r\n",
                'timeout' => 10,
            ],
        ]);

        $url = 'https://api.github.com/repos/marcpope/borgbackupserver/releases/latest';
        $json = @file_get_contents($url, false, $ctx);

        if ($json === false) {
            // Check if it was a 404 (no releases yet) vs actual failure
            $lastError = error_get_last()['message'] ?? '';
            if (str_contains($lastError, '404')) {
                $this->setSetting('last_update_check', date('Y-m-d H:i:s'));
                return [
                    'version' => $this->getCurrentVersion(),
                    'current' => $this->getCurrentVersion(),
                    'update_available' => false,
                    'notes' => '',
                    'url' => '',
                    'message' => 'No releases published yet.',
                ];
            }
            return ['error' => 'Could not reach GitHub API'];
        }

        $release = json_decode($json, true);
        if (!$release || empty($release['tag_name'])) {
            return ['error' => 'Invalid response from GitHub'];
        }

        $version = ltrim($release['tag_name'], 'v');
        $notes = $release['body'] ?? '';
        $htmlUrl = $release['html_url'] ?? '';

        $this->setSetting('latest_version', $version);
        $this->setSetting('latest_release_notes', $notes);
        $this->setSetting('latest_release_url', $htmlUrl);
        $this->setSetting('last_update_check', date('Y-m-d H:i:s'));

        return [
            'version' => $version,
            'notes' => $notes,
            'url' => $htmlUrl,
            'current' => $this->getCurrentVersion(),
            'update_available' => version_compare($version, $this->getCurrentVersion(), '>'),
        ];
    }

    public function performUpgrade(): array
    {
        $log = [];
        $latest = $this->getSetting('latest_version', '');

        if (empty($latest)) {
            return ['success' => false, 'log' => ['No update information available. Check for updates first.']];
        }

        if (!version_compare($latest, $this->getCurrentVersion(), '>')) {
            return ['success' => false, 'log' => ['Already up to date (v' . $this->getCurrentVersion() . ').']];
        }

        // Check for active jobs
        $activeJobs = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM backup_jobs WHERE status IN ('sent', 'running')"
        );
        if ((int)$activeJobs['cnt'] > 0) {
            return [
                'success' => false,
                'log' => ["{$activeJobs['cnt']} job(s) still running. Wait for them to complete or cancel them before upgrading."],
            ];
        }

        // Enable maintenance mode
        $this->setSetting('maintenance_mode', '1');
        $log[] = '[1/5] Maintenance mode enabled — new backups paused';

        try {
            // Git fetch
            $tag = 'v' . $latest;
            $output = '';
            $code = 0;

            exec("cd " . escapeshellarg($this->projectRoot) . " && git fetch origin 2>&1", $lines, $code);
            $output = implode("\n", $lines);
            $log[] = "[2/5] git fetch origin" . ($code === 0 ? ' — OK' : " — FAILED (exit {$code})");
            if ($output) $log[] = $output;

            if ($code !== 0) {
                $this->setSetting('maintenance_mode', '0');
                return ['success' => false, 'log' => $log];
            }

            // Git checkout tag
            $lines = [];
            exec("cd " . escapeshellarg($this->projectRoot) . " && git checkout " . escapeshellarg($tag) . " 2>&1", $lines, $code);
            $output = implode("\n", $lines);
            $log[] = "[3/5] git checkout {$tag}" . ($code === 0 ? ' — OK' : " — FAILED (exit {$code})");
            if ($output) $log[] = $output;

            if ($code !== 0) {
                $this->setSetting('maintenance_mode', '0');
                return ['success' => false, 'log' => $log];
            }

            // Composer install
            $lines = [];
            exec("cd " . escapeshellarg($this->projectRoot) . " && composer install --no-dev --no-interaction 2>&1", $lines, $code);
            $output = implode("\n", $lines);
            $log[] = "[4/5] composer install --no-dev" . ($code === 0 ? ' — OK' : " — WARNING (exit {$code})");
            if ($output) $log[] = $output;

            // Run migrations
            try {
                $migrator = new Migrator();
                $ran = $migrator->run();
                if (empty($ran)) {
                    $log[] = '[5/5] Migrations — no pending migrations';
                } else {
                    $log[] = '[5/5] Migrations — applied: ' . implode(', ', $ran);
                }
            } catch (\Exception $e) {
                $log[] = '[5/5] Migrations — ERROR: ' . $e->getMessage();
            }

            $log[] = '';
            $log[] = 'Upgrade to v' . $latest . ' complete.';

        } finally {
            // Always disable maintenance mode
            $this->setSetting('maintenance_mode', '0');
            $log[] = 'Maintenance mode disabled — backups resumed.';
        }

        // Save upgrade log
        $this->setSetting('last_upgrade_log', implode("\n", $log));

        return ['success' => true, 'log' => $log];
    }

    private function getSetting(string $key, string $default = ''): string
    {
        $row = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
        return $row['value'] ?? $default;
    }

    private function setSetting(string $key, string $value): void
    {
        $existing = $this->db->fetchOne("SELECT `key` FROM settings WHERE `key` = ?", [$key]);
        if ($existing) {
            $this->db->update('settings', ['value' => $value], "`key` = ?", [$key]);
        } else {
            $this->db->query("INSERT INTO settings (`key`, `value`) VALUES (?, ?)", [$key, $value]);
        }
    }
}

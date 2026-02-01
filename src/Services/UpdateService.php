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
        $this->checkIfStale();
        $latest = $this->getSetting('latest_version', '');
        if (empty($latest)) return false;
        return version_compare($latest, $this->getCurrentVersion(), '>');
    }

    /**
     * Auto-check for updates if last check was more than 24 hours ago.
     */
    public function checkIfStale(): void
    {
        $lastCheck = $this->getSetting('last_update_check', '');
        if (!empty($lastCheck)) {
            $lastTime = strtotime($lastCheck);
            if ($lastTime !== false && (time() - $lastTime) < 86400) {
                return;
            }
        }
        $this->checkForUpdate();
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

        // Use /releases (not /releases/latest) to include pre-releases
        $url = 'https://api.github.com/repos/marcpope/borgbackupserver/releases';
        $json = @file_get_contents($url, false, $ctx);

        if ($json === false) {
            return ['error' => 'Could not reach GitHub API'];
        }

        $releases = json_decode($json, true);
        if (!is_array($releases) || empty($releases)) {
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

        // First entry is the most recent release (including pre-releases)
        $release = $releases[0];
        if (empty($release['tag_name'])) {
            return ['error' => 'Invalid response from GitHub'];
        }

        $tag = $release['tag_name'];
        $version = ltrim($tag, 'v');
        $notes = $release['body'] ?? '';
        $htmlUrl = $release['html_url'] ?? '';

        $this->setSetting('latest_version', $version);
        $this->setSetting('latest_release_tag', $tag);
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
        $log[] = 'Maintenance mode enabled — new backups paused';

        try {
            // Run bbs-update via sudo with tag for release checkout
            $updateScript = $this->projectRoot . '/bin/bbs-update';
            $tag = $this->getSetting('latest_release_tag', 'v' . $latest);
            $lines = [];
            $code = 0;

            exec("sudo " . escapeshellarg($updateScript) . " " . escapeshellarg($this->projectRoot) . " " . escapeshellarg($tag) . " 2>&1", $lines, $code);
            foreach ($lines as $line) {
                $log[] = $line;
            }

            if ($code !== 0) {
                $log[] = '';
                $log[] = "Update script exited with code {$code}.";
                $this->setSetting('maintenance_mode', '0');
                return ['success' => false, 'log' => $log];
            }

            $log[] = '';
            $log[] = 'Upgrade complete.';

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

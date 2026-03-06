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

    /**
     * Detect if the application is running inside a Docker container.
     */
    public static function isRunningInDocker(): bool
    {
        return file_exists('/.dockerenv');
    }

    public function getIncludePrereleases(): bool
    {
        return $this->getSetting('include_prereleases', '0') === '1';
    }

    public function setIncludePrereleases(bool $value): void
    {
        $this->setSetting('include_prereleases', $value ? '1' : '0');
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

        // Filter by prerelease preference
        $includePrereleases = $this->getSetting('include_prereleases', '0') === '1';
        $release = null;
        foreach ($releases as $r) {
            if (!empty($r['draft'])) continue;
            if (!$includePrereleases && !empty($r['prerelease'])) continue;
            $release = $r;
            break;
        }

        if (!$release) {
            $this->setSetting('last_update_check', date('Y-m-d H:i:s'));
            return [
                'version' => $this->getCurrentVersion(),
                'current' => $this->getCurrentVersion(),
                'update_available' => false,
                'notes' => '',
                'url' => '',
                'message' => 'No stable releases published yet.',
            ];
        }
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

        $this->sendTelemetryPing();

        return [
            'version' => $version,
            'notes' => $notes,
            'url' => $htmlUrl,
            'current' => $this->getCurrentVersion(),
            'update_available' => version_compare($version, $this->getCurrentVersion(), '>'),
        ];
    }

    /**
     * Start a background upgrade process.
     *
     * @param string|null $branchOrTag  Pass 'main' for dev sync, null for latest release tag
     */
    public function startBackgroundUpgrade(?string $branchOrTag = null): array
    {
        // Already upgrading?
        if ($this->getSetting('upgrade_in_progress') === '1') {
            return ['success' => false, 'error' => 'An upgrade is already in progress.'];
        }

        $tag = $branchOrTag;
        if ($tag === null) {
            $latest = $this->getSetting('latest_version', '');
            if (empty($latest)) {
                return ['success' => false, 'error' => 'No update information available. Check for updates first.'];
            }
            if (!version_compare($latest, $this->getCurrentVersion(), '>')) {
                return ['success' => false, 'error' => 'Already up to date (v' . $this->getCurrentVersion() . ').'];
            }
            $tag = $this->getSetting('latest_release_tag', 'v' . $latest);
        }

        // Check for active backup jobs
        $activeJobs = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM backup_jobs WHERE status IN ('sent', 'running')"
        );
        if ((int) $activeJobs['cnt'] > 0) {
            return ['success' => false, 'error' => "{$activeJobs['cnt']} backup job(s) still running. Wait for them to complete or cancel them before upgrading."];
        }

        // Check for active server jobs (prune, compact, etc.)
        try {
            $activeServerJobs = $this->db->fetchOne(
                "SELECT COUNT(*) as cnt FROM server_jobs WHERE status IN ('queued', 'running')"
            );
            if ((int) ($activeServerJobs['cnt'] ?? 0) > 0) {
                return ['success' => false, 'error' => "{$activeServerJobs['cnt']} server job(s) still running. Wait for them to complete before upgrading."];
            }
        } catch (\Exception $e) { /* server_jobs table may not exist */ }

        // Enable maintenance mode to suspend the queue
        $this->setSetting('maintenance_mode', '1');

        // Set up log file
        $logFile = '/tmp/bbs-upgrade-' . getmypid() . '.log';
        $this->setSetting('upgrade_in_progress', '1');
        $this->setSetting('upgrade_started_at', date('Y-m-d H:i:s'));
        $this->setSetting('upgrade_log_file', $logFile);
        $this->setSetting('upgrade_target', $tag);
        // Clear any previous result
        $this->setSetting('upgrade_result', '');
        $this->setSetting('upgrade_completed_at', '');

        // Spawn background process
        $updateScript = $this->projectRoot . '/bin/bbs-update';
        $cmd = sprintf(
            'nohup sudo %s %s %s > %s 2>&1 & echo $!',
            escapeshellarg($updateScript),
            escapeshellarg($this->projectRoot),
            escapeshellarg($tag),
            escapeshellarg($logFile)
        );
        $pid = trim(shell_exec($cmd));
        $this->setSetting('upgrade_pid', $pid);

        return ['success' => true, 'pid' => $pid];
    }

    /**
     * Get the current status of a background upgrade.
     */
    public function getUpgradeStatus(): array
    {
        $inProgress = $this->getSetting('upgrade_in_progress') === '1';
        $result = $this->getSetting('upgrade_result', '');
        $startedAt = $this->getSetting('upgrade_started_at', '');
        $completedAt = $this->getSetting('upgrade_completed_at', '');
        $target = $this->getSetting('upgrade_target', '');

        if (!$inProgress && empty($result)) {
            return ['in_progress' => false, 'result' => null];
        }

        // If already completed (detected on a previous poll), return stored result
        if (!$inProgress && !empty($result)) {
            $log = $this->getSetting('last_upgrade_log', '');
            return [
                'in_progress' => false,
                'progress' => 100,
                'log' => $log,
                'last_line' => $this->extractLastMeaningfulLine($log),
                'result' => $result,
                'target' => $target,
                'started_at' => $startedAt,
                'completed_at' => $completedAt,
                'elapsed' => $this->calcElapsed($startedAt, $completedAt),
            ];
        }

        // In progress — read live log
        $logFile = $this->getSetting('upgrade_log_file', '');
        $pid = $this->getSetting('upgrade_pid', '');
        $log = '';
        if (!empty($logFile) && file_exists($logFile)) {
            $log = file_get_contents($logFile);
        }

        // Parse progress from [N/12] pattern
        $progress = 0;
        $totalSteps = 12;
        if (preg_match_all('/\[(\d+)\/(\d+)\]/', $log, $matches)) {
            $lastStep = (int) end($matches[1]);
            $totalSteps = (int) end($matches[2]);
            $progress = $totalSteps > 0 ? (int) round(($lastStep / $totalSteps) * 100) : 0;
        }

        // Check if process is still running
        $processRunning = false;
        if (!empty($pid) && is_numeric($pid)) {
            $processRunning = file_exists("/proc/{$pid}");
        }

        // Check for completion marker
        $completed = str_contains($log, '=== Update complete ===');
        $failed = !$processRunning && !$completed && !empty($log);

        if ($completed || $failed) {
            $resultStr = $completed ? 'success' : 'failed';
            $now = date('Y-m-d H:i:s');

            $this->setSetting('upgrade_in_progress', '0');
            $this->setSetting('maintenance_mode', '0');
            $this->setSetting('upgrade_completed_at', $now);
            $this->setSetting('upgrade_result', $resultStr);
            $this->setSetting('last_upgrade_log', $log);

            return [
                'in_progress' => false,
                'progress' => $completed ? 100 : $progress,
                'log' => $log,
                'last_line' => $this->extractLastMeaningfulLine($log),
                'result' => $resultStr,
                'target' => $target,
                'started_at' => $startedAt,
                'completed_at' => $now,
                'elapsed' => $this->calcElapsed($startedAt, $now),
            ];
        }

        return [
            'in_progress' => true,
            'progress' => $progress,
            'log' => $log,
            'last_line' => $this->extractLastMeaningfulLine($log),
            'result' => null,
            'target' => $target,
            'started_at' => $startedAt,
            'elapsed' => $this->calcElapsed($startedAt),
        ];
    }

    /**
     * Clear upgrade state after user acknowledges completion.
     */
    public function clearUpgrade(): void
    {
        $this->setSetting('upgrade_in_progress', '0');
        $this->setSetting('upgrade_result', '');
        $this->setSetting('upgrade_pid', '');
        $this->setSetting('upgrade_log_file', '');
        $this->setSetting('upgrade_started_at', '');
        $this->setSetting('upgrade_completed_at', '');
        $this->setSetting('upgrade_target', '');
        // Ensure maintenance mode is off
        $this->setSetting('maintenance_mode', '0');
    }

    private function extractLastMeaningfulLine(string $log): string
    {
        $lines = array_filter(array_map('trim', explode("\n", $log)), fn($l) => $l !== '');
        return !empty($lines) ? end($lines) : '';
    }

    private function calcElapsed(string $startedAt, ?string $endAt = null): int
    {
        if (empty($startedAt)) return 0;
        $start = strtotime($startedAt);
        $end = $endAt ? strtotime($endAt) : time();
        return max(0, $end - $start);
    }

    /**
     * Send anonymous telemetry ping (version + OS) once per version.
     */
    private function sendTelemetryPing(): void
    {
        try {
            if ($this->getSetting('telemetry_opt_out', '0') === '1') {
                return;
            }

            $currentVersion = $this->getCurrentVersion();
            if ($this->getSetting('telemetry_last_version') === $currentVersion) {
                return;
            }

            $os = php_uname('s') . ' ' . php_uname('r');
            if (file_exists('/etc/os-release')) {
                $osRelease = parse_ini_file('/etc/os-release');
                if (!empty($osRelease['PRETTY_NAME'])) {
                    $os = $osRelease['PRETTY_NAME'];
                }
            }

            $payload = json_encode([
                'version' => $currentVersion,
                'os' => $os,
            ]);

            // Set optimistically so we don't retry if endpoint is down
            $this->setSetting('telemetry_last_version', $currentVersion);

            // Fire-and-forget via non-blocking socket (won't hang if server is down)
            $host = 'www.borgbackupserver.com';
            $path = '/api/telemetry.php';
            $fp = @fsockopen('ssl://' . $host, 443, $errno, $errstr, 2);
            if ($fp) {
                $header = "POST {$path} HTTP/1.1\r\n";
                $header .= "Host: {$host}\r\n";
                $header .= "Content-Type: application/json\r\n";
                $header .= "User-Agent: BorgBackupServer/{$currentVersion}\r\n";
                $header .= "Content-Length: " . strlen($payload) . "\r\n";
                $header .= "Connection: close\r\n\r\n";
                fwrite($fp, $header . $payload);
                fclose($fp);
            }
        } catch (\Exception $e) {
            // Silently ignore telemetry failures
        }
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

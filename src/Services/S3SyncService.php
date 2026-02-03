<?php

namespace BBS\Services;

use BBS\Core\Database;

class S3SyncService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Resolve S3 credentials from plugin config.
     * If credential_source is 'global', loads from settings table.
     * If 'custom', uses the config values directly.
     */
    public function resolveCredentials(array $config): array
    {
        $source = $config['credential_source'] ?? 'global';

        if ($source === 'global') {
            $settings = [];
            $rows = $this->db->fetchAll("SELECT `key`, `value` FROM settings WHERE `key` LIKE 's3_%'");
            foreach ($rows as $row) {
                // Strip 's3_' prefix to get field name
                $field = substr($row['key'], 3);
                $settings[$field] = $row['value'];
            }

            // Decrypt sensitive fields from global settings
            foreach (['access_key', 'secret_key'] as $sensitive) {
                if (!empty($settings[$sensitive])) {
                    try {
                        $settings[$sensitive] = Encryption::decrypt($settings[$sensitive]);
                    } catch (\Exception $e) {
                        // May already be plaintext
                    }
                }
            }

            // Allow per-config overrides for path_prefix and bandwidth_limit
            return [
                'endpoint' => $settings['endpoint'] ?? '',
                'region' => $settings['region'] ?? '',
                'bucket' => $settings['bucket'] ?? '',
                'access_key' => $settings['access_key'] ?? '',
                'secret_key' => $settings['secret_key'] ?? '',
                'path_prefix' => $config['path_prefix'] ?? $settings['path_prefix'] ?? '',
                'bandwidth_limit' => $config['bandwidth_limit'] ?? '',
            ];
        }

        // Custom credentials — decrypt sensitive fields
        $secretKey = $config['secret_key'] ?? '';
        if (!empty($secretKey)) {
            try {
                $secretKey = Encryption::decrypt($secretKey);
            } catch (\Exception $e) {
                // May already be plaintext
            }
        }

        $accessKey = $config['access_key'] ?? '';
        if (!empty($accessKey)) {
            try {
                $accessKey = Encryption::decrypt($accessKey);
            } catch (\Exception $e) {
                // May already be plaintext
            }
        }

        return [
            'endpoint' => $config['endpoint'] ?? '',
            'region' => $config['region'] ?? 'us-east-1',
            'bucket' => $config['bucket'] ?? '',
            'access_key' => $accessKey,
            'secret_key' => $secretKey,
            'path_prefix' => $config['path_prefix'] ?? '',
            'bandwidth_limit' => $config['bandwidth_limit'] ?? '',
        ];
    }

    /**
     * Build environment variables for rclone (env-based config, no rclone.conf needed).
     */
    public function buildRcloneEnv(array $creds): array
    {
        $env = [
            'RCLONE_CONFIG_S3_TYPE' => 's3',
            'RCLONE_CONFIG_S3_PROVIDER' => 'Other',
            'RCLONE_CONFIG_S3_ACCESS_KEY_ID' => $creds['access_key'],
            'RCLONE_CONFIG_S3_SECRET_ACCESS_KEY' => $creds['secret_key'],
            'RCLONE_CONFIG_S3_ENDPOINT' => $creds['endpoint'],
            'RCLONE_CONFIG_S3_REGION' => $creds['region'],
        ];

        return $env;
    }

    /**
     * Sync a borg repository to S3.
     * Returns ['success' => bool, 'output' => string].
     */
    public function syncRepository(array $repo, array $agent, array $creds, ?string $runAsUser = null): array
    {
        if (empty($creds['bucket'])) {
            return ['success' => false, 'output' => 'No S3 bucket configured'];
        }

        if (!$this->isRcloneInstalled()) {
            return ['success' => false, 'output' => 'rclone is not installed on this server'];
        }

        // Build remote path: bucket/prefix/agent-name/repo-name/
        $agentName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $agent['name'] ?? 'unknown');
        $repoName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $repo['name'] ?? 'unknown');
        $prefix = trim($creds['path_prefix'], '/');
        $remotePath = $prefix ? "{$prefix}/{$agentName}/{$repoName}" : "{$agentName}/{$repoName}";
        $remote = "S3:{$creds['bucket']}/{$remotePath}/";

        // Get local repo path
        $localPath = \BBS\Services\BorgCommandBuilder::getLocalRepoPath($repo);
        if (empty($localPath) || !is_dir($localPath)) {
            return ['success' => false, 'output' => "Local repo path not found: {$localPath}"];
        }

        // Build rclone command
        $cmd = ['rclone', 'sync', $localPath, $remote, '--transfers', '4', '--checkers', '8'];

        if (!empty($creds['bandwidth_limit'])) {
            $cmd[] = '--bwlimit';
            $cmd[] = $creds['bandwidth_limit'];
        }

        // Build environment
        $env = $this->buildRcloneEnv($creds);

        // Run via bbs-ssh-helper (runs as root via sudo, then sudo -u to the repo user)
        if ($runAsUser) {
            $cmd = [
                'sudo', '/usr/local/bin/bbs-ssh-helper', 'rclone-sync',
                $runAsUser, $localPath, $remote,
                $creds['endpoint'] ?? '', $creds['region'] ?? '',
                $creds['access_key'] ?? '', $creds['secret_key'] ?? '',
            ];
            if (!empty($creds['bandwidth_limit'])) {
                $cmd[] = '--bwlimit';
                $cmd[] = $creds['bandwidth_limit'];
            }
        }

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // For non-sudo runs (e.g. test connection), pass env vars directly
        $envStrings = [];
        foreach ($env as $k => $v) {
            $envStrings[$k] = $v;
        }

        $proc = proc_open($cmd, $desc, $pipes, null, array_merge($_SERVER, $envStrings));
        if (!is_resource($proc)) {
            return ['success' => false, 'output' => 'Failed to start rclone process'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        $fullOutput = trim($stdout . "\n" . $stderr);

        // Extract just the final stats line (e.g. "65.046M / 65.046 MBytes, 100%, 26.503 MBytes/s, ETA 0s")
        $summary = '';
        if ($exitCode === 0 && !empty($fullOutput)) {
            $lines = array_filter(array_map('trim', explode("\n", $fullOutput)));
            $lastLine = end($lines);
            // Strip rclone log prefix (e.g. "2026/02/02 23:44:04 INFO : ")
            $summary = preg_replace('/^\d{4}\/\d{2}\/\d{2}\s+\d{2}:\d{2}:\d{2}\s+\w+\s+:\s+/', '', $lastLine);
        }

        return [
            'success' => $exitCode === 0,
            'output' => $exitCode === 0
                ? ($summary ?: 'Sync completed')
                : ($fullOutput ?: "rclone exited with code {$exitCode}"),
        ];
    }

    /**
     * Restore a borg repository from S3.
     * Returns ['success' => bool, 'output' => string].
     *
     * @param array $repo Target repository to restore into
     * @param array $agent Agent that owns the repository
     * @param array $creds S3 credentials
     * @param string|null $runAsUser Unix user to run as
     * @param array|null $sourceRepo For "copy" mode: the source repo to pull S3 data from
     */
    public function restoreRepository(array $repo, array $agent, array $creds, ?string $runAsUser = null, ?array $sourceRepo = null): array
    {
        if (empty($creds['bucket'])) {
            return ['success' => false, 'output' => 'No S3 bucket configured'];
        }

        if (!$this->isRcloneInstalled()) {
            return ['success' => false, 'output' => 'rclone is not installed on this server'];
        }

        // Build remote path: bucket/prefix/agent-name/repo-name/
        // For "copy" mode, use sourceRepo's name for the S3 path
        $agentName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $agent['name'] ?? 'unknown');
        $sourceRepoName = $sourceRepo['name'] ?? $repo['name'];
        $repoName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sourceRepoName ?? 'unknown');
        $prefix = trim($creds['path_prefix'], '/');
        $remotePath = $prefix ? "{$prefix}/{$agentName}/{$repoName}" : "{$agentName}/{$repoName}";
        $remote = "S3:{$creds['bucket']}/{$remotePath}/";

        // Get local repo path
        $localPath = \BBS\Services\BorgCommandBuilder::getLocalRepoPath($repo);
        if (empty($localPath)) {
            return ['success' => false, 'output' => "Local repo path not configured"];
        }

        // Create local directory if it doesn't exist
        if (!is_dir($localPath)) {
            $parentDir = dirname($localPath);
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0755, true);
            }
        }

        // Build rclone command - sync FROM S3 TO local (reverse of syncRepository)
        $cmd = ['rclone', 'sync', $remote, $localPath, '--transfers', '4', '--checkers', '8'];

        if (!empty($creds['bandwidth_limit'])) {
            $cmd[] = '--bwlimit';
            $cmd[] = $creds['bandwidth_limit'];
        }

        // Build environment
        $env = $this->buildRcloneEnv($creds);

        // Run via bbs-ssh-helper (runs as root via sudo, then sudo -u to the repo user)
        if ($runAsUser) {
            $cmd = [
                'sudo', '/usr/local/bin/bbs-ssh-helper', 'rclone-restore',
                $runAsUser, $remote, $localPath,
                $creds['endpoint'] ?? '', $creds['region'] ?? '',
                $creds['access_key'] ?? '', $creds['secret_key'] ?? '',
            ];
            if (!empty($creds['bandwidth_limit'])) {
                $cmd[] = '--bwlimit';
                $cmd[] = $creds['bandwidth_limit'];
            }
        }

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // For non-sudo runs, pass env vars directly
        $envStrings = [];
        foreach ($env as $k => $v) {
            $envStrings[$k] = $v;
        }

        $proc = proc_open($cmd, $desc, $pipes, null, array_merge($_SERVER, $envStrings));
        if (!is_resource($proc)) {
            return ['success' => false, 'output' => 'Failed to start rclone process'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        $fullOutput = trim($stdout . "\n" . $stderr);

        // Extract summary
        $summary = '';
        if ($exitCode === 0 && !empty($fullOutput)) {
            $lines = array_filter(array_map('trim', explode("\n", $fullOutput)));
            $lastLine = end($lines);
            $summary = preg_replace('/^\d{4}\/\d{2}\/\d{2}\s+\d{2}:\d{2}:\d{2}\s+\w+\s+:\s+/', '', $lastLine);
        }

        return [
            'success' => $exitCode === 0,
            'output' => $exitCode === 0
                ? ($summary ?: 'Restore completed')
                : ($fullOutput ?: "rclone exited with code {$exitCode}"),
        ];
    }

    /**
     * Test S3 connection by listing the bucket root.
     */
    public function testConnection(array $creds): array
    {
        if (empty($creds['bucket'])) {
            return ['success' => false, 'error' => 'No bucket configured'];
        }

        if (!$this->isRcloneInstalled()) {
            return ['success' => false, 'error' => 'rclone is not installed on this server. Install with: apt install rclone'];
        }

        $remote = "S3:{$creds['bucket']}/";
        $cmd = ['rclone', 'lsd', $remote, '--max-depth', '1'];

        $env = $this->buildRcloneEnv($creds);

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $envStrings = [];
        foreach ($env as $k => $v) {
            $envStrings[$k] = $v;
        }

        $proc = proc_open($cmd, $desc, $pipes, null, array_merge($_SERVER, $envStrings));
        if (!is_resource($proc)) {
            return ['success' => false, 'error' => 'Failed to start rclone process'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0) {
            $error = trim($stderr) ?: trim($stdout) ?: "rclone exited with code {$exitCode}";
            return ['success' => false, 'error' => $error];
        }

        return ['success' => true];
    }

    /**
     * Check if rclone is installed.
     */
    public function isRcloneInstalled(): bool
    {
        $output = @shell_exec('which rclone 2>/dev/null');
        return !empty(trim($output ?? ''));
    }

    /**
     * List remote repositories in S3 for a given agent.
     * Returns array of repo names found in S3 for this agent.
     */
    public function listRemoteRepos(string $agentName, array $creds): array
    {
        if (empty($creds['bucket'])) {
            return ['success' => false, 'repos' => [], 'error' => 'No S3 bucket configured'];
        }

        if (!$this->isRcloneInstalled()) {
            return ['success' => false, 'repos' => [], 'error' => 'rclone is not installed'];
        }

        // Sanitize agent name same way as sync
        $agentName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $agentName);
        $prefix = trim($creds['path_prefix'], '/');
        $remotePath = $prefix ? "{$prefix}/{$agentName}/" : "{$agentName}/";
        $remote = "S3:{$creds['bucket']}/{$remotePath}";

        // List directories (repos) under the agent folder
        $cmd = ['rclone', 'lsd', $remote];

        $env = $this->buildRcloneEnv($creds);

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $envStrings = [];
        foreach ($env as $k => $v) {
            $envStrings[$k] = $v;
        }

        $proc = proc_open($cmd, $desc, $pipes, null, array_merge($_SERVER, $envStrings));
        if (!is_resource($proc)) {
            return ['success' => false, 'repos' => [], 'error' => 'Failed to start rclone process'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        // Exit code 3 means directory not found (no repos for this agent yet)
        if ($exitCode === 3) {
            return ['success' => true, 'repos' => []];
        }

        if ($exitCode !== 0) {
            $error = trim($stderr) ?: trim($stdout) ?: "rclone exited with code {$exitCode}";
            return ['success' => false, 'repos' => [], 'error' => $error];
        }

        // Parse rclone lsd output - format: "          -1 2024-01-15 10:30:00        -1 repo-name"
        $repos = [];
        $lines = array_filter(array_map('trim', explode("\n", $stdout)));
        foreach ($lines as $line) {
            // Last space-separated token is the directory name
            $parts = preg_split('/\s+/', $line);
            if (!empty($parts)) {
                $repoName = end($parts);
                if (!empty($repoName)) {
                    $repos[] = $repoName;
                }
            }
        }

        return ['success' => true, 'repos' => $repos];
    }

    /**
     * Delete a repository from S3.
     * Returns ['success' => bool, 'output' => string].
     */
    public function deleteFromS3(array $repo, array $agent, array $creds): array
    {
        if (empty($creds['bucket'])) {
            return ['success' => false, 'output' => 'No S3 bucket configured'];
        }

        if (!$this->isRcloneInstalled()) {
            return ['success' => false, 'output' => 'rclone is not installed on this server'];
        }

        // Build remote path: bucket/prefix/agent-name/repo-name/
        $agentName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $agent['name'] ?? 'unknown');
        $repoName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $repo['name'] ?? 'unknown');
        $prefix = trim($creds['path_prefix'], '/');
        $remotePath = $prefix ? "{$prefix}/{$agentName}/{$repoName}" : "{$agentName}/{$repoName}";
        $remote = "S3:{$creds['bucket']}/{$remotePath}/";

        // Use rclone purge to delete the directory and all contents
        $cmd = ['rclone', 'purge', $remote];

        $env = $this->buildRcloneEnv($creds);

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $envStrings = [];
        foreach ($env as $k => $v) {
            $envStrings[$k] = $v;
        }

        $proc = proc_open($cmd, $desc, $pipes, null, array_merge($_SERVER, $envStrings));
        if (!is_resource($proc)) {
            return ['success' => false, 'output' => 'Failed to start rclone process'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        $fullOutput = trim($stdout . "\n" . $stderr);

        return [
            'success' => $exitCode === 0,
            'output' => $exitCode === 0
                ? 'Deleted from S3'
                : ($fullOutput ?: "rclone exited with code {$exitCode}"),
        ];
    }
}

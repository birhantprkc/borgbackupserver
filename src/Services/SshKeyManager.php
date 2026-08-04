<?php

namespace BBS\Services;

use BBS\Core\Database;

class SshKeyManager
{
    private const SSH_HELPER = '/usr/local/bin/bbs-ssh-helper';

    /** Last stderr/exit info from a failed runHelper() call, for error surfacing */
    private static ?string $lastHelperError = null;

    /**
     * The error output of the most recent failed helper invocation (or null).
     * Lets callers show the real cause (e.g. a sudoers problem) instead of a
     * generic "provisioning failed" message (#368).
     */
    public static function getLastHelperError(): ?string
    {
        return self::$lastHelperError;
    }

    /**
     * Generate an SSH RSA key pair (RSA-4096 for maximum client compatibility).
     * Returns ['private_key' => string, 'public_key' => string].
     */
    public static function generateKeyPair(): array
    {
        $tmpDir = sys_get_temp_dir() . '/bbs-keygen-' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0700, true);
        $keyFile = $tmpDir . '/id_rsa';

        try {
            $cmd = ['ssh-keygen', '-t', 'rsa', '-b', '4096', '-m', 'PEM', '-N', '', '-f', $keyFile, '-C', 'bbs-agent'];
            $proc = proc_open($cmd, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes);

            if (!is_resource($proc)) {
                throw new \RuntimeException('Failed to run ssh-keygen');
            }

            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);

            if ($exitCode !== 0) {
                throw new \RuntimeException('ssh-keygen failed: ' . $stderr);
            }

            $privateKey = file_get_contents($keyFile);
            $publicKey = trim(file_get_contents($keyFile . '.pub'));

            return [
                'private_key' => $privateKey,
                'public_key' => $publicKey,
            ];
        } finally {
            // Cleanup
            @unlink($keyFile);
            @unlink($keyFile . '.pub');
            @rmdir($tmpDir);
        }
    }

    /**
     * Generate a safe Unix username from the client name.
     * Format: bbs-<sanitized_name>
     */
    public static function generateUnixUser(string $clientName): string
    {
        $safe = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', $clientName));
        $safe = substr($safe, 0, 28); // Keep total under 32 chars
        return 'bbs-' . ($safe ?: 'client');
    }

    /**
     * Provision SSH access for a client: create Unix user, configure authorized_keys.
     * Returns the Unix username on success.
     */
    public static function provisionClient(int $agentId, string $clientName, string $storagePath): ?array
    {
        $db = Database::getInstance();

        // Generate SSH key pair
        $keys = self::generateKeyPair();

        // Generate Unix username (ensure uniqueness)
        $baseUser = self::generateUnixUser($clientName);
        $unixUser = $baseUser;
        $existing = $db->fetchOne("SELECT id FROM agents WHERE ssh_unix_user = ? AND id != ?", [$unixUser, $agentId]);
        if ($existing) {
            $unixUser = $baseUser . '-' . $agentId;
        }

        // Home directory = storage path for this client
        $homeDir = rtrim($storagePath, '/') . '/' . $agentId;

        // Create Unix user via sudo helper
        $output = self::runHelper('create-user', $unixUser, $homeDir, $keys['public_key']);
        if ($output === null || !str_contains($output, 'OK')) {
            // Log the actual error for debugging
            $detail = $output ?: (self::$lastHelperError ?? 'bbs-ssh-helper returned no output');
            self::$lastHelperError = $detail;
            $db = Database::getInstance();
            $db->insert('server_log', [
                'agent_id' => $agentId,
                'level' => 'error',
                'message' => "SSH provisioning failed: {$detail}",
            ]);
            return null;
        }

        // Store keys and home directory in database
        $db->update('agents', [
            'ssh_unix_user' => $unixUser,
            'ssh_public_key' => $keys['public_key'],
            'ssh_private_key_encrypted' => Encryption::encrypt($keys['private_key']),
            'ssh_home_dir' => $homeDir,
        ], 'id = ?', [$agentId]);

        return [
            'unix_user' => $unixUser,
            'public_key' => $keys['public_key'],
            'private_key' => $keys['private_key'],
            'home_dir' => $homeDir,
        ];
    }

    /**
     * Remove SSH access for a client.
     */
    public static function deprovisionClient(string $unixUser): void
    {
        self::runHelper('delete-user', $unixUser);
    }

    /**
     * Remove a client's storage directory via the SSH helper (runs as root).
     */
    public static function deleteStorage(string $directory): void
    {
        self::runHelper('delete-storage', $directory);
    }

    /**
     * Build the SSH repo path for an agent.
     * Format: ssh://bbs-clientname@serverhost/./reponame
     */
    public static function buildSshRepoPath(string $unixUser, string $serverHost, string $repoName): string
    {
        // Strip web port from server_host (e.g. "192.168.1.200:8080" → "192.168.1.200")
        // SSH port is handled separately via BORG_RSH -p
        $host = self::stripHostPort($serverHost);
        return "ssh://{$unixUser}@{$host}/./{$repoName}";
    }

    /**
     * Strip port from a host string (e.g. "example.com:8080" → "example.com").
     * The server_host setting may include the web port from APP_URL, but SSH
     * repo paths must not include it — the SSH port is set via BORG_RSH.
     */
    public static function stripHostPort(string $host): string
    {
        // Handle IPv6 addresses like [::1]:8080
        if (str_contains($host, ']')) {
            return preg_replace('/]:\d+$/', ']', $host);
        }
        return preg_replace('/:\d+$/', '', $host);
    }

    /**
     * Build the local repo path (server-side access for prune/compact).
     * Format: /storage/path/agentId/repoName
     */
    public static function buildLocalRepoPath(string $storagePath, int $agentId, string $repoName): string
    {
        return rtrim($storagePath, '/') . '/' . $agentId . '/' . $repoName;
    }

    /**
     * Rewrite the host in an agent's local ssh:// repo paths. Local repo paths
     * bake the server host in at creation time, so a later change to the
     * per-client override or the global server_host setting must update the
     * stored paths too (#367). Returns the number of repos updated.
     */
    public static function rewriteAgentRepoHosts(\BBS\Core\Database $db, int $agentId, string $newHost): int
    {
        $host = self::stripHostPort(trim($newHost));
        if ($host === '') {
            return 0;
        }

        $repos = $db->fetchAll(
            "SELECT id, path FROM repositories
             WHERE agent_id = ? AND storage_type = 'local' AND path LIKE 'ssh://%'",
            [$agentId]
        );

        $updated = 0;
        foreach ($repos as $repo) {
            $newPath = preg_replace_callback(
                '/^(ssh:\/\/[^@\/]+@)[^\/]+/',
                fn($m) => $m[1] . $host,
                $repo['path']
            );
            if ($newPath !== null && $newPath !== $repo['path']) {
                $db->update('repositories', ['path' => $newPath], 'id = ?', [$repo['id']]);
                $updated++;
            }
        }
        return $updated;
    }

    /**
     * Update the .storage-paths file for an agent (used by bbs-ssh-gate to allow
     * borg access to storage locations outside the agent's SSH home directory).
     * Gathers all unique storage location agent directories and writes them via
     * bbs-ssh-helper.
     */
    public static function updateAgentStoragePaths(\BBS\Core\Database $db, int $agentId, array $agent): void
    {
        // Get agent's home directory from stored ssh_home_dir
        $homeDir = $agent['ssh_home_dir'] ?? null;
        if (!$homeDir) {
            return; // No SSH provisioned — can't update storage paths
        }

        // The parent of the home dir (e.g., /var/bbs/home from /var/bbs/home/3)
        // bbs-ssh-gate already allows access to $homeDir, so any storage location
        // under the same parent is already accessible. We only need to add paths
        // for locations on different base paths.
        $homeParent = rtrim(dirname($homeDir), '/');

        // Find all storage locations that have local repos for this agent
        $locations = $db->fetchAll(
            "SELECT DISTINCT sl.path FROM repositories r
             JOIN storage_locations sl ON sl.id = r.storage_location_id
             WHERE r.agent_id = ? AND r.storage_type = 'local'",
            [$agentId]
        );

        // Build agent-specific paths for locations outside the home dir's parent
        $paths = [];
        foreach ($locations as $loc) {
            $locPath = rtrim($loc['path'], '/');
            if ($locPath === $homeParent) continue; // Already allowed via home dir
            $paths[] = $locPath . '/' . $agentId;
        }

        // Call bbs-ssh-helper to write the paths file
        $cmd = ['sudo', self::SSH_HELPER, 'update-storage-paths', $homeDir];
        foreach ($paths as $p) {
            $cmd[] = $p;
        }
        exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1', $output, $ret);
        if ($ret !== 0) {
            $db->insert('server_log', [
                'agent_id' => $agentId,
                'level' => 'warning',
                'message' => "update-storage-paths failed: " . implode(' ', $output),
            ]);
        }
    }

    /**
     * Run the SSH helper script via sudo.
     */
    private static function runHelper(string ...$args): ?string
    {
        $cmd = array_merge(['sudo', self::SSH_HELPER], $args);

        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($proc)) {
            self::$lastHelperError = 'failed to start bbs-ssh-helper';
            return null;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0) {
            self::$lastHelperError = trim($stderr ?: $stdout) ?: "exit code {$exitCode} with no output";
            error_log("bbs-ssh-helper failed (exit $exitCode): $stderr");
            return null;
        }

        self::$lastHelperError = null;
        return trim($stdout);
    }
}

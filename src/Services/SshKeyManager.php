<?php

namespace BBS\Services;

use BBS\Core\Database;

class SshKeyManager
{
    private const SSH_HELPER = '/usr/local/bin/bbs-ssh-helper';

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
            return null;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0) {
            error_log("bbs-ssh-helper failed (exit $exitCode): $stderr");
            return null;
        }

        return trim($stdout);
    }
}

<?php

namespace BBS\Services;

use BBS\Core\Database;

class RemoteSshService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get all remote SSH configs.
     */
    public function getAll(): array
    {
        return $this->db->fetchAll("SELECT * FROM remote_ssh_configs ORDER BY name");
    }

    /**
     * Get a single config by ID.
     */
    public function getById(int $id): ?array
    {
        return $this->db->fetchOne("SELECT * FROM remote_ssh_configs WHERE id = ?", [$id]);
    }

    /**
     * Get a config by ID with decrypted SSH key.
     */
    public function getDecrypted(int $id): ?array
    {
        $config = $this->getById($id);
        if (!$config) return null;

        try {
            $config['ssh_private_key'] = Encryption::decrypt($config['ssh_private_key_encrypted']);
        } catch (\Exception $e) {
            $config['ssh_private_key'] = $config['ssh_private_key_encrypted'];
        }

        return $config;
    }

    /**
     * Build the SSH repo path for a given config and repo name.
     * Returns: ssh://user@host:port/base_path/repoName
     */
    public function buildRepoPath(array $config, string $repoName): string
    {
        $basePath = rtrim($config['remote_base_path'] ?? './', '/');
        $port = (int) ($config['remote_port'] ?? 22);
        $appendName = (int) ($config['append_repo_name'] ?? 1);

        $path = $appendName ? "{$basePath}/{$repoName}" : $basePath;

        if ($port === 22) {
            return "ssh://{$config['remote_user']}@{$config['remote_host']}/{$path}";
        }

        return "ssh://{$config['remote_user']}@{$config['remote_host']}:{$port}/{$path}";
    }

    /**
     * Test connection to remote SSH host.
     * Runs borg --version (or custom borg_remote_path) over SSH.
     */
    public function testConnection(array $config): array
    {
        $keyFile = null;
        try {
            $sshKey = $this->decryptKey($config);
            $keyFile = $this->writeTempKey($sshKey);

            $borgBin = $config['borg_remote_path'] ?: 'borg';
            $port = (int) ($config['remote_port'] ?? 22);

            $sshCmd = [
                'ssh',
                '-i', $keyFile,
                '-p', (string) $port,
                '-o', 'StrictHostKeyChecking=no',
                '-o', 'UserKnownHostsFile=/dev/null',
                '-o', 'BatchMode=yes',
                '-o', 'ConnectTimeout=10',
                "{$config['remote_user']}@{$config['remote_host']}",
                "{$borgBin} --version",
            ];

            $proc = proc_open($sshCmd, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, null, $this->buildServerEnv());

            if (!is_resource($proc)) {
                return ['success' => false, 'error' => 'Failed to start SSH process'];
            }

            fclose($pipes[0]);
            $stdout = trim(stream_get_contents($pipes[1]));
            $stderr = trim(stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);

            if ($exitCode === 0 && !empty($stdout)) {
                return ['success' => true, 'version' => $stdout];
            }

            return ['success' => false, 'error' => $stderr ?: $stdout ?: "SSH connection failed (exit code {$exitCode})"];
        } finally {
            $this->cleanupTempKey($keyFile);
        }
    }

    /**
     * Initialize a borg repository on the remote host.
     */
    public function initRepo(array $config, string $repoPath, string $encryption, string $passphrase = ''): array
    {
        $borgRemotePath = $config['borg_remote_path'] ?? null;
        $cmd = ['borg', 'init', '--encryption=' . $encryption];
        if ($borgRemotePath) {
            $cmd[] = '--remote-path=' . $borgRemotePath;
        }
        $cmd[] = $repoPath;

        $env = $this->buildServerEnv();
        if (!empty($passphrase)) {
            $env['BORG_PASSPHRASE'] = $passphrase;
        }
        $env['BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK'] = 'yes';
        $env['BORG_RELOCATED_REPO_ACCESS_IS_OK'] = 'yes';

        return $this->runBorgWithKey($config, $cmd, $env);
    }

    /**
     * Run a borg command against a remote repo (prune, compact, check, list, info, etc.)
     *
     * @param array  $config    Remote SSH config (with encrypted key)
     * @param string $repoPath  Full SSH repo path (ssh://user@host/./repo)
     * @param array  $borgArgs  Borg subcommand args (e.g. ['prune', '--keep-daily=7', $repoPath])
     * @param string $passphrase Repository passphrase (already decrypted)
     */
    public function runBorgCommand(array $config, string $repoPath, array $borgArgs, string $passphrase = ''): array
    {
        $borgRemotePath = $config['borg_remote_path'] ?? null;

        $cmd = array_merge(['borg'], $borgArgs);

        // Insert --remote-path after the subcommand if needed
        if ($borgRemotePath && count($borgArgs) >= 1) {
            array_splice($cmd, 2, 0, ['--remote-path=' . $borgRemotePath]);
        }

        $env = $this->buildServerEnv();
        if (!empty($passphrase)) {
            $env['BORG_PASSPHRASE'] = $passphrase;
        }
        $env['BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK'] = 'yes';
        $env['BORG_RELOCATED_REPO_ACCESS_IS_OK'] = 'yes';

        return $this->runBorgWithKey($config, $cmd, $env);
    }

    /**
     * Execute a borg command with the SSH key from the config.
     * Handles temp key creation/cleanup.
     */
    private function runBorgWithKey(array $config, array $cmd, array $env): array
    {
        $keyFile = null;
        try {
            $sshKey = $this->decryptKey($config);
            $keyFile = $this->writeTempKey($sshKey);

            $port = (int) ($config['remote_port'] ?? 22);
            $env['BORG_RSH'] = "ssh -i {$keyFile} -p {$port} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o BatchMode=yes";

            $proc = proc_open($cmd, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, null, $env);

            if (!is_resource($proc)) {
                return ['success' => false, 'output' => 'Failed to start borg process', 'exit_code' => -1];
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);

            return [
                'success' => $exitCode <= 1,
                'exit_code' => $exitCode,
                'output' => $stdout,
                'stderr' => $stderr,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'output' => $e->getMessage(), 'exit_code' => -1];
        } finally {
            $this->cleanupTempKey($keyFile);
        }
    }

    /**
     * Decrypt the SSH private key from a config record.
     */
    private function decryptKey(array $config): string
    {
        try {
            return Encryption::decrypt($config['ssh_private_key_encrypted']);
        } catch (\Exception $e) {
            // May be stored in plaintext
            return $config['ssh_private_key_encrypted'];
        }
    }

    /**
     * Write SSH key to a temp file with 0600 permissions.
     */
    private function writeTempKey(string $key): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'bbs-ssh-');
        // Normalize line endings (Windows \r\n → Unix \n) and ensure trailing newline
        $key = str_replace("\r\n", "\n", $key);
        $key = str_replace("\r", "\n", $key);
        $key = rtrim($key) . "\n";
        file_put_contents($tmpFile, $key);
        chmod($tmpFile, 0600);
        return $tmpFile;
    }

    /**
     * Remove temp key file.
     */
    private function cleanupTempKey(?string $path): void
    {
        if ($path && file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Build base environment for server-side borg execution.
     */
    private function buildServerEnv(): array
    {
        $cacheDir = '/var/bbs/cache/www-data';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0700, true);
        }

        return [
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'BORG_BASE_DIR' => $cacheDir,
            'HOME' => $cacheDir,
        ];
    }

    /**
     * Open a streaming borg process for remote SSH repos.
     * Returns process handle, pipes, and temp key path for cleanup.
     * Caller must fclose pipes, proc_close, and call cleanupStreamingProcess().
     *
     * @return array{proc: resource, pipes: array, key_file: string}|array{error: string}
     */
    public function openBorgProcess(array $config, array $borgArgs, string $passphrase = ''): array
    {
        $borgRemotePath = $config['borg_remote_path'] ?? null;
        $cmd = array_merge(['borg'], $borgArgs);
        if ($borgRemotePath && count($borgArgs) >= 1) {
            array_splice($cmd, 2, 0, ['--remote-path=' . $borgRemotePath]);
        }

        $env = $this->buildServerEnv();
        if (!empty($passphrase)) {
            $env['BORG_PASSPHRASE'] = $passphrase;
        }
        $env['BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK'] = 'yes';
        $env['BORG_RELOCATED_REPO_ACCESS_IS_OK'] = 'yes';

        try {
            $sshKey = $this->decryptKey($config);
            $keyFile = $this->writeTempKey($sshKey);
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }

        $port = (int) ($config['remote_port'] ?? 22);
        $env['BORG_RSH'] = "ssh -i {$keyFile} -p {$port} -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o BatchMode=yes";

        $proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $env);

        if (!is_resource($proc)) {
            $this->cleanupTempKey($keyFile);
            return ['error' => 'Failed to start borg process'];
        }

        fclose($pipes[0]);

        return ['proc' => $proc, 'pipes' => $pipes, 'key_file' => $keyFile];
    }

    /**
     * Clean up after openBorgProcess().
     */
    public function cleanupStreamingProcess(array $handle): void
    {
        $this->cleanupTempKey($handle['key_file'] ?? null);
    }

    /**
     * Count repositories using a given remote SSH config.
     */
    public function getRepoCount(int $configId): int
    {
        $result = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM repositories WHERE remote_ssh_config_id = ?",
            [$configId]
        );
        return (int) ($result['cnt'] ?? 0);
    }
}

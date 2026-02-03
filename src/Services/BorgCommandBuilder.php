<?php

namespace BBS\Services;

class BorgCommandBuilder
{
    /**
     * Build the borg create command arguments for a backup plan.
     */
    public static function buildCreateCommand(array $plan, array $repo, string $archiveName): array
    {
        $cmd = ['borg', 'create'];

        // JSON output on stdout for archive stats (original_size, deduplicated_size)
        $cmd[] = '--json';
        // JSON logging on stderr for progress parsing + file list for catalog
        $cmd[] = '--log-json';
        $cmd[] = '--list';
        $cmd[] = '--progress';

        // Advanced options from the plan
        // Borg flags like --pattern take a value that may start with + or -
        // which confuses argparse. We join these as --flag=value to avoid ambiguity.
        if (!empty($plan['advanced_options'])) {
            $tokens = preg_split('/\s+/', trim($plan['advanced_options']));
            $flagsWithValues = ['--pattern', '--compression', '--exclude', '--exclude-from',
                '--patterns-from', '--comment', '--chunker-params', '--remote-path'];
            for ($i = 0; $i < count($tokens); $i++) {
                $token = $tokens[$i];
                if (empty($token)) continue;
                if (in_array($token, $flagsWithValues) && isset($tokens[$i + 1])) {
                    $cmd[] = $token . '=' . $tokens[$i + 1];
                    $i++;
                } else {
                    $cmd[] = $token;
                }
            }
        }

        // Exclude patterns
        if (!empty($plan['excludes'])) {
            $excludes = preg_split('/[\n\r]+/', trim($plan['excludes']));
            foreach ($excludes as $pattern) {
                $pattern = trim($pattern);
                if (!empty($pattern)) {
                    $cmd[] = '--exclude';
                    $cmd[] = $pattern;
                }
            }
        }

        // Repository::archive
        $cmd[] = $repo['path'] . '::' . $archiveName;

        // Directories to back up (one per line or space-delimited)
        $dirs = preg_split('/[\s\n\r]+/', trim($plan['directories']));
        foreach ($dirs as $dir) {
            if (!empty($dir)) {
                $cmd[] = $dir;
            }
        }

        return $cmd;
    }

    /**
     * Build the borg prune command arguments.
     */
    public static function buildPruneCommand(array $plan, array $repo, ?string $archivePrefix = null): array
    {
        $cmd = ['borg', 'prune', '--list', '--log-json'];

        // Scope prune to archives from this specific plan
        if ($archivePrefix) {
            $cmd[] = '--glob-archives=' . $archivePrefix . '-*';
        }

        if ($plan['prune_minutes'] > 0) $cmd[] = '--keep-minutely=' . $plan['prune_minutes'];
        if ($plan['prune_hours'] > 0)   $cmd[] = '--keep-hourly=' . $plan['prune_hours'];
        if ($plan['prune_days'] > 0)    $cmd[] = '--keep-daily=' . $plan['prune_days'];
        if ($plan['prune_weeks'] > 0)   $cmd[] = '--keep-weekly=' . $plan['prune_weeks'];
        if ($plan['prune_months'] > 0)  $cmd[] = '--keep-monthly=' . $plan['prune_months'];
        if ($plan['prune_years'] > 0)   $cmd[] = '--keep-yearly=' . $plan['prune_years'];

        $cmd[] = $repo['path'];

        return $cmd;
    }

    /**
     * Build the borg init command for a new repository.
     */
    public static function buildInitCommand(array $repo): array
    {
        $cmd = ['borg', 'init', '--encryption=' . $repo['encryption'], $repo['path']];
        return $cmd;
    }

    /**
     * Build the borg list command.
     */
    public static function buildListCommand(array $repo, ?string $archiveName = null): array
    {
        $cmd = ['borg', 'list', '--json'];
        $target = $repo['path'];
        if ($archiveName) {
            $target .= '::' . $archiveName;
        }
        $cmd[] = $target;
        return $cmd;
    }

    /**
     * Build the borg info command.
     */
    public static function buildInfoCommand(array $repo): array
    {
        return ['borg', 'info', '--json', $repo['path']];
    }

    /**
     * Build a borg extract (restore) command.
     */
    /**
     * Build a borg extract (restore) command.
     * Note: borg 1.x has no --destination flag. Callers should set the working
     * directory (cwd) to the desired extraction target instead.
     */
    public static function buildExtractCommand(array $repo, string $archiveName, array $paths = []): array
    {
        $cmd = ['borg', 'extract', '--log-json'];

        $cmd[] = $repo['path'] . '::' . $archiveName;

        foreach ($paths as $path) {
            // Borg extract expects paths without leading slash
            $cmd[] = ltrim($path, '/');
        }

        return $cmd;
    }

    /**
     * Generate an archive name based on current timestamp.
     */
    public static function generateArchiveName(string $prefix = 'backup'): string
    {
        return $prefix . '-' . date('Y-m-d_H-i-s');
    }

    /**
     * Build the environment variables needed for borg (passphrase, SSH).
     *
     * When $forAgent is true (default), includes BORG_RSH for SSH key-based access.
     * When false (server-side execution), omits BORG_RSH since we access repos locally.
     */
    public static function buildEnv(array $repo, bool $forAgent = true): array
    {
        $env = [
            // Agent runs on a different machine than where the repo was created
            'BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK' => 'yes',
            // Allow repos that were restored from S3 (copies share the same UUID)
            'BORG_RELOCATED_REPO_ACCESS_IS_OK' => 'yes',
        ];
        if (!empty($repo['passphrase_encrypted']) && ($repo['encryption'] ?? '') !== 'none') {
            try {
                $env['BORG_PASSPHRASE'] = Encryption::decrypt($repo['passphrase_encrypted']);
            } catch (\Exception $e) {
                // Fallback: passphrase might be stored in plaintext (pre-encryption migration)
                $env['BORG_PASSPHRASE'] = $repo['passphrase_encrypted'];
            }
        }

        if ($forAgent) {
            // For agent-side execution, set BORG_RSH to use the agent's SSH key
            if (self::isSshRepo($repo['path'] ?? '')) {
                $env['BORG_RSH'] = 'ssh -i /etc/bbs-agent/ssh_key -o StrictHostKeyChecking=no -o BatchMode=yes';
            }
        } else {
            // Server-side: www-data can't write to /var/www/.config, redirect borg's config/cache
            $cacheDir = '/var/bbs/cache/www-data';
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0700, true);
            }
            $env['BORG_BASE_DIR'] = $cacheDir;
            $env['HOME'] = $cacheDir;
        }

        return $env;
    }

    /**
     * Check if a repo path is an SSH path.
     */
    public static function isSshRepo(string $path): bool
    {
        return str_starts_with($path, 'ssh://');
    }

    /**
     * Get the local path for a repo (for server-side operations like prune).
     * Converts ssh://user@host/./reponame to the actual local path using storage location.
     */
    public static function getLocalRepoPath(array $repo): string
    {
        if (!self::isSshRepo($repo['path'])) {
            return $repo['path'];
        }

        // For SSH repos, the local path is stored separately or derived from storage location
        // Derive from storage_path setting + agent_id + repo name
        if (!empty($repo['local_path'])) {
            return $repo['local_path'];
        }

        // Derive from storage_path setting + agent_id + repo name
        $db = \BBS\Core\Database::getInstance();
        $setting = $db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
        if ($setting) {
            return rtrim($setting['value'], '/') . '/' . $repo['agent_id'] . '/' . $repo['name'];
        }

        return $repo['path'];
    }

    /**
     * Convert command array to a JSON task payload for the agent.
     */
    public static function toTaskPayload(string $taskType, array $cmd, array $env = [], array $extra = []): array
    {
        return array_merge([
            'task' => $taskType,
            'command' => $cmd,
            'env' => $env,
        ], $extra);
    }
}

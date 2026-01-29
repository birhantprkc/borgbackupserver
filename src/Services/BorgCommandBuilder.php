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

        // JSON logging for progress parsing + file list for catalog
        $cmd[] = '--log-json';
        $cmd[] = '--list';
        $cmd[] = '--progress';

        // Advanced options from the plan
        if (!empty($plan['advanced_options'])) {
            $opts = preg_split('/\s+/', trim($plan['advanced_options']));
            foreach ($opts as $opt) {
                if (!empty($opt)) {
                    $cmd[] = $opt;
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
    public static function buildPruneCommand(array $plan, array $repo): array
    {
        $cmd = ['borg', 'prune', '--log-json'];

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
    public static function buildExtractCommand(array $repo, string $archiveName, array $paths = [], ?string $destination = null): array
    {
        $cmd = ['borg', 'extract', '--log-json'];

        if ($destination) {
            $cmd[] = '--destination=' . $destination;
        }

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
     * Build the environment variables needed for borg (passphrase).
     */
    public static function buildEnv(array $repo): array
    {
        $env = [
            // Agent runs on a different machine than where the repo was created
            'BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK' => 'yes',
        ];
        if (!empty($repo['passphrase_encrypted']) && ($repo['encryption'] ?? '') !== 'none') {
            try {
                $env['BORG_PASSPHRASE'] = Encryption::decrypt($repo['passphrase_encrypted']);
            } catch (\Exception $e) {
                // Fallback: passphrase might be stored in plaintext (pre-encryption migration)
                $env['BORG_PASSPHRASE'] = $repo['passphrase_encrypted'];
            }
        }
        return $env;
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

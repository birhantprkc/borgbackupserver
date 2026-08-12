<?php

namespace BBS\Services;

use BBS\Core\Database;

/**
 * The server's backup of itself — database, configuration and SSH keys.
 *
 * Not repository data: that is protected separately. This is what makes a
 * fresh install recoverable after the original machine is gone.
 *
 * Lives here so the daily scheduler run and an on-demand request share one
 * implementation, rather than the button drifting from the schedule.
 */
class ServerBackupService
{
    private const HELPER = '/usr/local/bin/bbs-ssh-helper';
    private const BACKUP_DIR = '/var/bbs/backups';

    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    private function setting(string $key, ?string $default = null): ?string
    {
        $row = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = ?", [$key]);
        return $row['value'] ?? $default;
    }

    public function isEnabled(): bool
    {
        return $this->setting('self_backup_enabled', '1') === '1';
    }

    public function lastRunAt(): ?string
    {
        return $this->setting('last_self_backup');
    }

    /** True when the daily run is due — more than a day since the last one. */
    public function isDue(): bool
    {
        $last = $this->lastRunAt();
        return !$last || strtotime($last) < time() - 86400;
    }

    /**
     * Take a backup now, using the configured options.
     *
     * @return array{success: bool, message: string, filename: ?string}
     */
    public function run(): array
    {
        if (!is_file(self::HELPER)) {
            return ['success' => false, 'message' => 'Backup helper is not installed on this server', 'filename' => null];
        }

        $args = '';
        if ($this->setting('self_backup_catalogs', '0') === '1') {
            $args .= ' --with-catalogs';
        }
        $keep = max(1, (int) $this->setting('self_backup_retention', '7'));
        $args .= ' --keep ' . $keep;

        $before = $this->newestBackupFile();
        $output = shell_exec('sudo ' . self::HELPER . ' server-backup' . $args . ' 2>&1');
        $ok = str_contains($output ?? '', 'OK');

        // Stamp the run either way: a failing backup that retried every minute
        // would be worse than one that waits for the next daily window.
        $this->db->query(
            "INSERT INTO settings (`key`, `value`) VALUES ('last_self_backup', ?)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
            [date('Y-m-d H:i:s')]
        );

        if (!$ok) {
            return [
                'success' => false,
                'message' => trim($output ?? '') ?: 'Backup failed with no output',
                'filename' => null,
            ];
        }

        $after = $this->newestBackupFile();
        return [
            'success' => true,
            'message' => 'Server backup completed',
            // Null when retention swept the new file away immediately, or the
            // directory is somehow empty — the caller shouldn't name a file
            // that isn't there.
            'filename' => ($after && $after !== $before) ? $after : null,
        ];
    }

    /** Newest file in the backup directory, by name (they are timestamped). */
    private function newestBackupFile(): ?string
    {
        $files = glob(self::BACKUP_DIR . '/bbs-backup-*.tar.gz') ?: [];
        if (empty($files)) {
            return null;
        }
        rsort($files);
        return basename($files[0]);
    }

    /**
     * Push the local server backups to off-site storage, if that is enabled.
     *
     * @return array{success: bool, message: string, skipped: bool}
     */
    public function syncToS3(): array
    {
        if ($this->setting('s3_sync_server_backups', '0') !== '1') {
            return ['success' => true, 'message' => 'Off-site sync is not enabled', 'skipped' => true];
        }

        $s3 = new S3SyncService();
        $creds = $s3->resolveCredentials(['credential_source' => 'global']);
        if (empty($creds['bucket']) || !$s3->isRcloneInstalled()) {
            return ['success' => false, 'message' => 'Off-site storage is not configured', 'skipped' => false];
        }

        $prefix = trim($creds['path_prefix'], '/');
        $remotePath = $prefix ? "{$prefix}/_server-backups" : '_server-backups';
        $remote = "S3:{$creds['bucket']}/{$remotePath}/";

        $cmd = sprintf(
            'sudo %s rclone-server-sync %s %s %s %s %s %s 2>&1',
            escapeshellarg(self::HELPER),
            escapeshellarg(self::BACKUP_DIR),
            escapeshellarg($remote),
            escapeshellarg($creds['endpoint']),
            escapeshellarg($creds['region']),
            escapeshellarg($creds['access_key']),
            escapeshellarg($creds['secret_key'])
        );
        $output = shell_exec($cmd);
        // Same test the scheduler has always used: rclone chatters "ERROR" for
        // recoverable things, so a run is only a failure when it says ERROR and
        // never reaches OK.
        $failed = str_contains($output ?? '', 'ERROR') && !str_contains($output ?? '', 'OK');

        return [
            'success' => !$failed,
            'message' => $failed ? (trim($output ?? '') ?: 'Sync failed') : 'Synced to off-site storage',
            'skipped' => false,
        ];
    }
}

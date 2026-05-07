<?php

namespace BBS\Services;

use BBS\Core\Database;

/**
 * Refreshes repositories.size_bytes after events that actually change repo
 * size: backup completion, prune, compact, and archive_delete.
 *
 * Local repos: `du` via bbs-ssh-helper.
 * Remote SSH repos: `borg info --json <repo>` and read cache.stats.unique_csize
 * (the deduplicated/compressed on-disk size); falls back to
 * SUM(archives.deduplicated_size) if borg info can't be reached.
 *
 * Previously a scheduler loop ran `du` on every local repo every 5 minutes,
 * which kept spinning disks awake on idle home servers. Now disks are only
 * touched when BBS itself modified the repo.
 */
class RepositorySizeService
{
    public static function refresh(int $repoId): void
    {
        $db = Database::getInstance();
        $repo = $db->fetchOne("SELECT * FROM repositories WHERE id = ?", [$repoId]);
        if (!$repo) return;

        if (($repo['storage_type'] ?? 'local') === 'remote_ssh') {
            $unique = self::fetchRepoUniqueCsize($repo);
            if ($unique !== null) {
                $db->update('repositories', ['size_bytes' => $unique], 'id = ?', [$repoId]);
                return;
            }
            // Fall back to SUM of per-archive deduplicated_size when borg info
            // can't be reached. Known to under-report (it's a sum of the
            // *incremental* contributions of each archive at the time it was
            // created), but it's better than a stale value.
            $db->query(
                "UPDATE repositories SET size_bytes = COALESCE(
                    (SELECT SUM(deduplicated_size) FROM archives WHERE repository_id = ?), 0
                ) WHERE id = ?",
                [$repoId, $repoId]
            );
            return;
        }

        $localPath = BorgCommandBuilder::getLocalRepoPath($repo);
        if (empty($localPath)) return;

        $output = [];
        exec('sudo /usr/local/bin/bbs-ssh-helper get-size ' . escapeshellarg($localPath) . ' 2>/dev/null', $output);
        if (!empty($output[0]) && is_numeric($output[0])) {
            $db->update('repositories', ['size_bytes' => (int) $output[0]], 'id = ?', [$repoId]);
        }
    }

    /**
     * Run `borg info --json <repo>` (no archive suffix) and return
     * cache.stats.unique_csize — the deduplicated on-disk size of the whole
     * repo as borg itself reports it. Returns null if the call fails or the
     * JSON shape isn't recognised.
     *
     * Accepts either a local or remote_ssh repo row. The optional
     * $sshUnixUser overrides the agent lookup (catalog sync passes its own
     * value joined to the job row).
     */
    public static function fetchRepoUniqueCsize(array $repo, ?string $sshUnixUser = null): ?int
    {
        $passphrase = '';
        if (!empty($repo['passphrase_encrypted'])) {
            try {
                $passphrase = Encryption::decrypt($repo['passphrase_encrypted']);
            } catch (\Throwable $e) {
                // Treat as missing — borg will prompt and fail, which we handle below.
            }
        }

        $json = null;

        if (($repo['storage_type'] ?? 'local') === 'remote_ssh' && !empty($repo['remote_ssh_config_id'])) {
            $svc = new RemoteSshService();
            $config = $svc->getById((int) $repo['remote_ssh_config_id']);
            if (!$config) return null;
            $result = $svc->runBorgCommand($config, $repo['path'], ['info', '--json', $repo['path']], $passphrase);
            if (empty($result['success'])) return null;
            $json = json_decode($result['output'] ?? '', true);
        } else {
            $localPath = BorgCommandBuilder::getLocalRepoPath($repo);
            if (empty($localPath)) return null;

            if ($sshUnixUser === null) {
                $db = Database::getInstance();
                $agent = $db->fetchOne("SELECT ssh_unix_user FROM agents WHERE id = ?", [(int) $repo['agent_id']]);
                $sshUnixUser = $agent['ssh_unix_user'] ?? null;
            }

            if (!empty($sshUnixUser)) {
                $cmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'borg-cmd',
                        $sshUnixUser, '-', 'info', '--json', $localPath];
                $env = null;
            } else {
                $cmd = ['borg', 'info', '--json', $localPath];
                $envExtra = [];
                if ($passphrase !== '') $envExtra['BORG_PASSPHRASE'] = $passphrase;
                $envExtra['BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK'] = 'yes';
                $envExtra['BORG_RELOCATED_REPO_ACCESS_IS_OK'] = 'yes';
                $envExtra['BORG_BASE_DIR'] = '/tmp/bbs-borg-www-data';
                $envExtra['HOME'] = '/tmp/bbs-borg-www-data';
                $env = array_filter($_SERVER, 'is_string') + $envExtra;
            }

            $proc = proc_open($cmd, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, null, $env);
            if (!is_resource($proc)) return null;
            if (!empty($sshUnixUser)) {
                fwrite($pipes[0], $passphrase . "\n");
            }
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
            if ($exit !== 0) return null;
            $json = json_decode($stdout, true);
        }

        $unique = $json['cache']['stats']['unique_csize'] ?? null;
        return is_numeric($unique) ? (int) $unique : null;
    }
}

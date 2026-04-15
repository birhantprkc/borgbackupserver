<?php

namespace BBS\Services;

use BBS\Core\Database;

/**
 * Refreshes repositories.size_bytes after events that actually change repo
 * size: backup completion, prune, compact, and archive_delete.
 *
 * Runs `du` on local repos via bbs-ssh-helper, or falls back to
 * SUM(archives.deduplicated_size) for remote SSH repos where we can't du.
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
            // Can't du a remote SSH repo — fall back to SUM of deduplicated sizes.
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
}

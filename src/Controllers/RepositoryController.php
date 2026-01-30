<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\BorgCommandBuilder;
use BBS\Services\Encryption;
use BBS\Services\SshKeyManager;

class RepositoryController extends Controller
{
    public function store(): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $agentId = (int) ($_POST['agent_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $encryption = $_POST['encryption'] ?? 'repokey-blake2';
        $passphrase = $_POST['passphrase'] ?? '';

        if (empty($name) || empty($agentId)) {
            $this->flash('danger', 'Repository name and agent are required.');
            $this->redirect("/clients/{$agentId}");
        }

        // Verify agent access
        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$agentId]);
        if (!$agent || (!$this->isAdmin() && $agent['user_id'] != $_SESSION['user_id'])) {
            $this->flash('danger', 'Access denied.');
            $this->redirect('/clients');
        }

        // Build repo path using single storage_path setting
        $storageSetting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
        $storagePath = $storageSetting['value'] ?? '';
        $serverHost = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");
        $host = $serverHost['value'] ?? '';

        if (!empty($agent['ssh_unix_user']) && !empty($host)) {
            $path = SshKeyManager::buildSshRepoPath($agent['ssh_unix_user'], $host, $name);
        } else {
            $path = rtrim($storagePath, '/') . '/' . $agentId . '/' . $name;
        }

        // Auto-generate passphrase if not provided and encryption is enabled
        if (empty($passphrase) && $encryption !== 'none') {
            $passphrase = $this->generatePassphrase();
        }

        $repoId = $this->db->insert('repositories', [
            'agent_id' => $agentId,
            'name' => $name,
            'path' => $path,
            'encryption' => $encryption,
            'passphrase_encrypted' => $encryption !== 'none' ? Encryption::encrypt($passphrase) : null,
        ]);

        // Run borg init server-side (repos are local to server)
        $repo = $this->db->fetchOne("SELECT * FROM repositories WHERE id = ?", [$repoId]);
        $localPath = BorgCommandBuilder::getLocalRepoPath($repo);

        // Create repo directory via SSH helper (sets correct ownership for borg + sshd)
        $helperCmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'create-repo-dir', $localPath];
        exec(implode(' ', array_map('escapeshellarg', $helperCmd)) . ' 2>&1', $helperOutput, $helperRet);
        if ($helperRet !== 0) {
            $this->db->insert('server_log', [
                'agent_id' => $agentId,
                'level' => 'warning',
                'message' => "create-repo-dir helper failed: " . implode(' ', $helperOutput),
            ]);
            // Fallback: create parent directory manually
            $parentDir = dirname($localPath);
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0755, true);
            }
        }

        // Build and run borg init using proc_open for clean env handling
        $env = $_ENV;
        if ($encryption !== 'none' && !empty($passphrase)) {
            $env['BORG_PASSPHRASE'] = $passphrase;
        }
        $env['BORG_UNKNOWN_UNENCRYPTED_REPO_ACCESS_IS_OK'] = 'yes';
        $env['BORG_BASE_DIR'] = '/tmp/bbs-borg-www-data';
        $env['HOME'] = '/tmp/bbs-borg-www-data';

        $initCmd = ['borg', 'init', '--encryption=' . $encryption, $localPath];

        $proc = proc_open($initCmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $env);

        $output = [];
        $retval = -1;
        if (is_resource($proc)) {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $retval = proc_close($proc);
            if (!empty($stdout)) $output[] = $stdout;
            if (!empty($stderr)) $output[] = $stderr;
        }

        if ($retval !== 0) {
            $errorMsg = implode("\n", $output);
            $this->db->insert('server_log', [
                'agent_id' => $agentId,
                'level' => 'error',
                'message' => "borg init failed for repo \"{$name}\": {$errorMsg}",
            ]);
            $this->flash('warning', "Repository \"{$name}\" created in database but borg init failed: {$errorMsg}");
            $this->redirect("/clients/{$agentId}?tab=repos");
        }

        // Fix ownership: borg init creates files as www-data, but the bbs-user needs to own them for SSH access
        if (!empty($agent['ssh_unix_user'])) {
            $fixCmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'fix-repo-perms', $localPath, $agent['ssh_unix_user']];
            exec(implode(' ', array_map('escapeshellarg', $fixCmd)) . ' 2>&1', $fixOutput, $fixRet);
            if ($fixRet !== 0) {
                $this->db->insert('server_log', [
                    'agent_id' => $agentId,
                    'level' => 'warning',
                    'message' => "fix-repo-perms failed: " . implode(' ', $fixOutput),
                ]);
            }
        }

        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'level' => 'info',
            'message' => "Repository \"{$name}\" initialized ({$encryption}) at {$localPath}",
        ]);

        $this->flash('success', "Repository \"{$name}\" created and initialized.");
        $this->redirect("/clients/{$agentId}?tab=repos");
    }

    public function delete(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $repo = $this->db->fetchOne("
            SELECT r.*, a.user_id
            FROM repositories r
            JOIN agents a ON a.id = r.agent_id
            WHERE r.id = ?
        ", [$id]);

        if (!$repo || (!$this->isAdmin() && $repo['user_id'] != $_SESSION['user_id'])) {
            $this->flash('danger', 'Repository not found.');
            $this->redirect('/clients');
        }

        $agentId = $repo['agent_id'];

        // Block if backup plans reference this repo
        $planCount = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM backup_plans WHERE repository_id = ?", [$id]
        );
        if ((int) ($planCount['cnt'] ?? 0) > 0) {
            $this->flash('danger', 'Cannot delete repository — it has backup plans attached. Delete the plans first.');
            $this->redirect("/clients/{$agentId}?tab=repos");
        }

        // Block if any jobs are currently in progress
        $activeJobs = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM backup_jobs WHERE repository_id = ? AND status IN ('queued', 'sent', 'running')", [$id]
        );
        if ((int) ($activeJobs['cnt'] ?? 0) > 0) {
            $this->flash('danger', 'Cannot delete repository — it has active jobs. Wait for them to finish first.');
            $this->redirect("/clients/{$agentId}?tab=repos");
        }

        // Delete borg repository from disk
        $localPath = BorgCommandBuilder::getLocalRepoPath($repo);
        $diskDeleted = false;
        if (!empty($localPath) && is_dir($localPath)) {
            // Safety: only delete paths within the configured storage path
            $storageSetting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
            $storagePath = $storageSetting['value'] ?? '';

            if (!empty($storagePath) && str_starts_with(realpath($localPath), realpath($storagePath))) {
                $output = [];
                $retval = 0;
                exec('rm -rf ' . escapeshellarg($localPath) . ' 2>&1', $output, $retval);
                $diskDeleted = ($retval === 0);
                if (!$diskDeleted) {
                    $this->db->insert('server_log', [
                        'agent_id' => $agentId,
                        'level' => 'warning',
                        'message' => "Failed to delete repo directory on disk: {$localPath} — " . implode(' ', $output),
                    ]);
                }
            } else {
                $this->db->insert('server_log', [
                    'agent_id' => $agentId,
                    'level' => 'warning',
                    'message' => "Skipped disk deletion for repo \"{$repo['name']}\" — path outside known storage location.",
                ]);
            }
        }

        $this->db->delete('repositories', 'id = ?', [$id]);

        $msg = "Repository \"{$repo['name']}\" deleted.";
        if ($diskDeleted) {
            $msg .= " Data removed from disk.";
        } elseif (!empty($localPath) && is_dir($localPath)) {
            $msg .= " Warning: disk data at {$localPath} could not be removed — clean up manually.";
        }

        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'level' => 'info',
            'message' => "Repository \"{$repo['name']}\" deleted" . ($diskDeleted ? " (disk data removed)" : ""),
        ]);

        $this->flash('success', $msg);
        $this->redirect("/clients/{$agentId}?tab=repos");
    }

    private function generatePassphrase(): string
    {
        $segments = [];
        for ($i = 0; $i < 5; $i++) {
            $segments[] = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
        }
        return implode('-', $segments);
    }
}

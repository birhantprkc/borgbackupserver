<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\BorgCommandBuilder;
use BBS\Services\Encryption;
use BBS\Services\PermissionService;
use BBS\Services\RemoteSshService;
use BBS\Services\S3SyncService;
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
        $storageType = $_POST['storage_type'] ?? 'local';
        $remoteSshConfigId = !empty($_POST['remote_ssh_config_id']) ? (int) $_POST['remote_ssh_config_id'] : null;

        if (empty($name) || empty($agentId)) {
            $this->flash('danger', 'Repository name and agent are required.');
            $this->redirect("/clients/{$agentId}");
        }

        // Verify agent access and manage_repos permission
        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$agentId]);
        if (!$agent || !$this->canAccessAgent($agentId)) {
            $this->flash('danger', 'Access denied.');
            $this->redirect('/clients');
        }
        $this->requirePermission(PermissionService::MANAGE_REPOS, $agentId);

        // Auto-generate passphrase if not provided and encryption is enabled
        if (empty($passphrase) && $encryption !== 'none') {
            $passphrase = $this->generatePassphrase();
        }

        // Branch based on storage type
        if ($storageType === 'remote_ssh') {
            $this->storeRemoteSsh($agentId, $name, $encryption, $passphrase, $remoteSshConfigId);
        } else {
            $this->storeLocal($agentId, $agent, $name, $encryption, $passphrase);
        }
    }

    /**
     * Create a local repository on the BBS server.
     */
    private function storeLocal(int $agentId, array $agent, string $name, string $encryption, string $passphrase): void
    {
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

        $repoId = $this->db->insert('repositories', [
            'agent_id' => $agentId,
            'storage_type' => 'local',
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
        $env['BORG_RELOCATED_REPO_ACCESS_IS_OK'] = 'yes';
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

    /**
     * Create a repository on a remote SSH host (rsync.net, BorgBase, etc.)
     */
    private function storeRemoteSsh(int $agentId, string $name, string $encryption, string $passphrase, ?int $remoteSshConfigId): void
    {
        if (!$remoteSshConfigId) {
            $this->flash('danger', 'Please select a remote SSH host.');
            $this->redirect("/clients/{$agentId}?tab=repos");
        }

        $remoteSshService = new RemoteSshService();
        $config = $remoteSshService->getById($remoteSshConfigId);
        if (!$config) {
            $this->flash('danger', 'Remote SSH host not found.');
            $this->redirect("/clients/{$agentId}?tab=repos");
        }

        // Build the SSH repo path
        $repoPath = $remoteSshService->buildRepoPath($config, $name);

        // Run borg init over SSH first — only save to DB if it succeeds
        $result = $remoteSshService->initRepo($config, $repoPath, $encryption, $passphrase);

        if (!$result['success']) {
            $errorMsg = $result['stderr'] ?? $result['output'] ?? 'Unknown error';
            $this->db->insert('server_log', [
                'agent_id' => $agentId,
                'level' => 'error',
                'message' => "borg init failed for remote repo \"{$name}\" on {$config['remote_host']}: {$errorMsg}",
            ]);
            $this->flash('danger', "Failed to initialize repository \"{$name}\" on {$config['remote_host']}: {$errorMsg}");
            $this->redirect("/clients/{$agentId}?tab=repos");
        }

        $repoId = $this->db->insert('repositories', [
            'agent_id' => $agentId,
            'storage_type' => 'remote_ssh',
            'remote_ssh_config_id' => $remoteSshConfigId,
            'name' => $name,
            'path' => $repoPath,
            'encryption' => $encryption,
            'passphrase_encrypted' => $encryption !== 'none' ? Encryption::encrypt($passphrase) : null,
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'level' => 'info',
            'message' => "Remote repository \"{$name}\" initialized ({$encryption}) on {$config['remote_user']}@{$config['remote_host']}",
        ]);

        $this->flash('success', "Repository \"{$name}\" created on {$config['remote_host']} and initialized.");
        $this->redirect("/clients/{$agentId}?tab=repos");
    }

    public function delete(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $repo = $this->db->fetchOne("
            SELECT r.*, a.id as agent_id
            FROM repositories r
            JOIN agents a ON a.id = r.agent_id
            WHERE r.id = ?
        ", [$id]);

        if (!$repo || !$this->canAccessAgent($repo['agent_id'])) {
            $this->flash('danger', 'Repository not found.');
            $this->redirect('/clients');
        }

        // Require manage_repos permission to delete
        $this->requirePermission(PermissionService::MANAGE_REPOS, $repo['agent_id']);

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
                exec('sudo /usr/local/bin/bbs-ssh-helper delete-storage ' . escapeshellarg($localPath) . ' 2>&1', $output, $retval);
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

        // Handle S3 deletion if requested
        $s3Deleted = false;
        $deleteFromS3 = !empty($_POST['delete_from_s3']);
        $pluginConfigId = (int) ($_POST['plugin_config_id'] ?? 0);

        if ($deleteFromS3 && $pluginConfigId > 0) {
            // Get plugin config and agent info
            $pluginConfig = $this->db->fetchOne("SELECT config FROM plugin_configs WHERE id = ?", [$pluginConfigId]);
            $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$agentId]);

            if ($pluginConfig && $agent) {
                $config = json_decode($pluginConfig['config'], true) ?: [];
                $s3Service = new S3SyncService();
                $creds = $s3Service->resolveCredentials($config);

                $result = $s3Service->deleteFromS3($repo, $agent, $creds);
                $s3Deleted = $result['success'];

                $this->db->insert('server_log', [
                    'agent_id' => $agentId,
                    'level' => $s3Deleted ? 'info' : 'warning',
                    'message' => $s3Deleted
                        ? "S3 data deleted for repository \"{$repo['name']}\""
                        : "Failed to delete S3 data for repository \"{$repo['name']}\": " . ($result['output'] ?? 'Unknown error'),
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
        if ($deleteFromS3) {
            if ($s3Deleted) {
                $msg .= " S3 offsite copy removed.";
            } else {
                $msg .= " Warning: S3 data could not be removed — clean up manually.";
            }
        }

        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'level' => 'info',
            'message' => "Repository \"{$repo['name']}\" deleted" . ($diskDeleted ? " (disk data removed)" : "") . ($s3Deleted ? " (S3 data removed)" : ""),
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

    /**
     * Queue a repository maintenance task (check, compact, repair, break_lock).
     */
    public function maintenance(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $action = $_POST['action'] ?? '';
        $validActions = ['check', 'compact', 'repair', 'break_lock', 'catalog_rebuild', 'catalog_rebuild_full'];
        if (!in_array($action, $validActions)) {
            $this->flash('danger', 'Invalid maintenance action.');
            $this->redirect('/clients');
        }

        $repo = $this->db->fetchOne("
            SELECT r.*, a.id as agent_id
            FROM repositories r
            JOIN agents a ON a.id = r.agent_id
            WHERE r.id = ?
        ", [$id]);

        if (!$repo || !$this->canAccessAgent($repo['agent_id'])) {
            $this->flash('danger', 'Repository not found.');
            $this->redirect('/clients');
        }

        // Require repo_maintenance permission
        $this->requirePermission(PermissionService::REPO_MAINTENANCE, $repo['agent_id']);

        // Check for active jobs on this repo
        $activeJob = $this->db->fetchOne(
            "SELECT id, task_type FROM backup_jobs WHERE repository_id = ? AND status IN ('queued', 'sent', 'running')",
            [$id]
        );
        if ($activeJob) {
            $this->flash('warning', "Cannot run maintenance — repository has an active {$activeJob['task_type']} job (#" . $activeJob['id'] . ').');
            $this->redirect("/clients/{$repo['agent_id']}?tab=repos");
        }

        // Map action to task_type
        $taskType = match($action) {
            'check' => 'repo_check',
            'compact' => 'compact',
            'repair' => 'repo_repair',
            'break_lock' => 'break_lock',
            'catalog_rebuild' => 'catalog_rebuild',
            'catalog_rebuild_full' => 'catalog_rebuild_full',
            default => null,
        };

        $actionLabel = match($action) {
            'check' => 'Check',
            'compact' => 'Compact',
            'repair' => 'Repair',
            'break_lock' => 'Break Lock',
            'catalog_rebuild' => 'Rebuild Catalog (Missing)',
            'catalog_rebuild_full' => 'Rebuild Catalog (Full)',
            default => $action,
        };

        // Queue the job
        $jobId = $this->db->insert('backup_jobs', [
            'agent_id' => $repo['agent_id'],
            'repository_id' => $id,
            'task_type' => $taskType,
            'status' => 'queued',
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $repo['agent_id'],
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => "{$actionLabel} job #{$jobId} queued for repository \"{$repo['name']}\"",
        ]);

        $this->flash('success', "{$actionLabel} job queued for repository \"{$repo['name']}\".");
        $this->redirect("/clients/{$repo['agent_id']}?tab=repos");
    }

    /**
     * Repository detail page.
     */
    public function detail(int $agentId, int $id): void
    {
        $this->requireAuth();

        $repo = $this->db->fetchOne("
            SELECT r.*, a.name as agent_name, a.ssh_unix_user,
                   rsc.name as remote_config_name, rsc.remote_host, rsc.remote_user, rsc.remote_port
            FROM repositories r
            JOIN agents a ON a.id = r.agent_id
            LEFT JOIN remote_ssh_configs rsc ON rsc.id = r.remote_ssh_config_id
            WHERE r.id = ? AND r.agent_id = ?
        ", [$id, $agentId]);

        if (!$repo || !$this->canAccessAgent($agentId)) {
            $this->flash('danger', 'Repository not found.');
            $this->redirect('/clients');
        }

        // Get archives for this repo
        $archives = $this->db->fetchAll("
            SELECT * FROM archives WHERE repository_id = ? ORDER BY created_at DESC
        ", [$id]);

        // Get plans using this repo
        $plans = $this->db->fetchAll("
            SELECT bp.*, s.enabled as schedule_enabled
            FROM backup_plans bp
            LEFT JOIN schedules s ON s.backup_plan_id = bp.id
            WHERE bp.repository_id = ?
        ", [$id]);

        // Get recent jobs for this repo
        $recentJobs = $this->db->fetchAll("
            SELECT * FROM backup_jobs
            WHERE repository_id = ?
            ORDER BY queued_at DESC LIMIT 20
        ", [$id]);

        // Check if repo has S3 sync enabled (via repository_s3_configs) — only for local repos
        $s3SyncInfo = null;
        $s3PluginConfigs = [];
        if (($repo['storage_type'] ?? 'local') === 'local') {
            $s3SyncInfo = $this->db->fetchOne("
                SELECT rsc.plugin_config_id, pc.name as config_name,
                       rsc.last_sync_at as last_s3_sync, rsc.enabled
                FROM repository_s3_configs rsc
                JOIN plugin_configs pc ON pc.id = rsc.plugin_config_id
                WHERE rsc.repository_id = ?
            ", [$id]);

            // Get available S3 plugin configs for this agent (for "Enable S3 Sync" option)
            $s3PluginConfigs = $this->db->fetchAll("
                SELECT pc.id, pc.name
                FROM plugin_configs pc
                JOIN plugins p ON p.id = pc.plugin_id
                WHERE p.slug = 's3_sync' AND pc.agent_id = ?
                ORDER BY pc.name
            ", [$agentId]);
        }

        // Check for active jobs on this repo
        $activeJob = $this->db->fetchOne(
            "SELECT id, task_type, status FROM backup_jobs WHERE repository_id = ? AND status IN ('queued', 'sent', 'running')",
            [$id]
        );

        // Get local path for display (null for remote repos)
        $localPath = BorgCommandBuilder::getLocalRepoPath($repo);

        // Calculate stats
        $totalSize = (int) $repo['size_bytes'];
        $archiveCount = (int) $repo['archive_count'];
        $oldestArchive = $this->db->fetchOne("SELECT MIN(created_at) as oldest FROM archives WHERE repository_id = ?", [$id]);
        $newestArchive = $this->db->fetchOne("SELECT MAX(created_at) as newest FROM archives WHERE repository_id = ?", [$id]);

        // Dedup stats from archives
        $dedupStats = $this->db->fetchOne(
            "SELECT SUM(original_size) as total_original, SUM(deduplicated_size) as total_dedup FROM archives WHERE repository_id = ?",
            [$id]
        );

        // Get agent's borg_version for display (repo columns may not be populated yet)
        $agentInfo = $this->db->fetchOne("SELECT borg_version FROM agents WHERE id = ?", [$agentId]);

        $this->view('repositories/detail', [
            'pageTitle' => $repo['name'],
            'repo' => $repo,
            'agentId' => $agentId,
            'localPath' => $localPath,
            'archives' => $archives,
            'plans' => $plans,
            'recentJobs' => $recentJobs,
            's3SyncInfo' => $s3SyncInfo,
            's3PluginConfigs' => $s3PluginConfigs,
            'activeJob' => $activeJob,
            'totalSize' => $totalSize,
            'archiveCount' => $archiveCount,
            'oldestArchive' => $oldestArchive['oldest'] ?? null,
            'newestArchive' => $newestArchive['newest'] ?? null,
            'totalOriginal' => (int) ($dedupStats['total_original'] ?? 0),
            'totalDedup' => (int) ($dedupStats['total_dedup'] ?? 0),
            'agentBorgVersion' => $agentInfo['borg_version'] ?? null,
        ]);
    }

    /**
     * Queue S3 restore job.
     * Modes: 'replace' (default) - overwrites existing local data
     *        'copy' - creates a new repository with the S3 data
     */
    public function s3Restore(int $agentId, int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $mode = $_POST['mode'] ?? 'replace';

        $repo = $this->db->fetchOne("
            SELECT r.*, a.name as agent_name, a.ssh_unix_user
            FROM repositories r
            JOIN agents a ON a.id = r.agent_id
            WHERE r.id = ? AND r.agent_id = ?
        ", [$id, $agentId]);

        if (!$repo || !$this->canAccessAgent($agentId)) {
            $this->flash('danger', 'Repository not found.');
            $this->redirect('/clients');
        }

        // Require repo_maintenance permission for S3 restore
        $this->requirePermission(PermissionService::REPO_MAINTENANCE, $agentId);

        // Get S3 config for this repo from repository_s3_configs
        $s3Config = $this->db->fetchOne("
            SELECT plugin_config_id
            FROM repository_s3_configs
            WHERE repository_id = ?
        ", [$id]);

        if (!$s3Config) {
            $this->flash('danger', 'This repository does not have S3 sync configured.');
            $this->redirect("/clients/{$agentId}/repo/{$id}");
        }

        // For 'copy' mode, create a new repository first
        $targetRepoId = $id;
        $targetRepoName = $repo['name'];
        if ($mode === 'copy') {
            // Use provided name or generate unique name for the copy
            $copyName = trim($_POST['copy_name'] ?? '');
            if (empty($copyName)) {
                $copyName = $repo['name'] . '-copy';
            }

            // Check if name already exists
            if ($this->db->fetchOne("SELECT id FROM repositories WHERE agent_id = ? AND name = ?", [$agentId, $copyName])) {
                $this->flash('danger', "Repository \"{$copyName}\" already exists. Choose a different name.");
                $this->redirect("/clients/{$agentId}/repo/{$id}");
            }

            // Build path for the copy
            $storageSetting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
            $storagePath = $storageSetting['value'] ?? '';
            $serverHost = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");
            $host = $serverHost['value'] ?? '';

            if (!empty($repo['ssh_unix_user']) && !empty($host)) {
                $copyPath = SshKeyManager::buildSshRepoPath($repo['ssh_unix_user'], $host, $copyName);
            } else {
                $copyPath = rtrim($storagePath, '/') . '/' . $agentId . '/' . $copyName;
            }

            // Create the new repository record
            $targetRepoId = $this->db->insert('repositories', [
                'agent_id' => $agentId,
                'name' => $copyName,
                'path' => $copyPath,
                'encryption' => $repo['encryption'],
                'passphrase_encrypted' => $repo['passphrase_encrypted'],
            ]);
            $targetRepoName = $copyName;

            // Create local directory via SSH helper
            $localPath = BorgCommandBuilder::getLocalRepoPath(['path' => $copyPath, 'agent_id' => $agentId, 'name' => $copyName]);
            $helperCmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'create-repo-dir', $localPath];
            exec(implode(' ', array_map('escapeshellarg', $helperCmd)) . ' 2>&1', $helperOutput, $helperRet);
            if ($helperRet !== 0) {
                $this->db->insert('server_log', [
                    'agent_id' => $agentId,
                    'level' => 'warning',
                    'message' => "create-repo-dir helper failed for S3 copy restore: " . implode(' ', $helperOutput),
                ]);
            }

            $this->db->insert('server_log', [
                'agent_id' => $agentId,
                'level' => 'info',
                'message' => "Created repository \"{$copyName}\" as copy target for S3 restore",
            ]);
        } else {
            // For 'replace' mode, check for active jobs on this repo
            $activeJob = $this->db->fetchOne(
                "SELECT id, task_type FROM backup_jobs WHERE repository_id = ? AND status IN ('queued', 'sent', 'running')",
                [$id]
            );
            if ($activeJob) {
                $this->flash('warning', "Cannot restore from S3 — repository has an active {$activeJob['task_type']} job (#" . $activeJob['id'] . ').');
                $this->redirect("/clients/{$agentId}/repo/{$id}");
            }
        }

        // Queue the S3 restore job on the target repo
        // For copy mode, source_repository_id tells the restore where to pull S3 data from
        $jobData = [
            'agent_id' => $agentId,
            'repository_id' => $targetRepoId,
            'task_type' => 's3_restore',
            'plugin_config_id' => $s3Config['plugin_config_id'],
            'status' => 'queued',
        ];
        if ($mode === 'copy') {
            $jobData['source_repository_id'] = $id;  // Original repo
        }
        $jobId = $this->db->insert('backup_jobs', $jobData);

        $modeLabel = $mode === 'copy' ? 'copy' : 'replace';
        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => "S3 restore ({$modeLabel}) job #{$jobId} queued for repository \"{$targetRepoName}\"",
        ]);

        $this->flash('success', "S3 restore ({$modeLabel}) job queued for repository \"{$targetRepoName}\".");
        $this->redirect("/clients/{$agentId}/repo/{$targetRepoId}");
    }

    /**
     * Restore an orphaned repository from S3 (exists in S3 but not locally).
     */
    public function restoreOrphan(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $repoName = trim($_POST['repo_name'] ?? '');
        $pluginConfigId = (int) ($_POST['plugin_config_id'] ?? 0);

        if (empty($repoName) || $pluginConfigId === 0) {
            $this->flash('danger', 'Invalid restore request.');
            $this->redirect("/clients/{$id}?tab=repos");
        }

        // Verify agent access
        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$id]);
        if (!$agent || !$this->canAccessAgent($id)) {
            $this->flash('danger', 'Access denied.');
            $this->redirect('/clients');
        }

        // Require manage_repos permission
        $this->requirePermission(PermissionService::MANAGE_REPOS, $id);

        // Check if repo already exists
        $existing = $this->db->fetchOne(
            "SELECT id FROM repositories WHERE agent_id = ? AND name = ?",
            [$id, $repoName]
        );
        if ($existing) {
            $this->flash('warning', "Repository \"{$repoName}\" already exists.");
            $this->redirect("/clients/{$id}?tab=repos");
        }

        // Get plugin config and resolve credentials
        $pluginConfig = $this->db->fetchOne("SELECT config FROM plugin_configs WHERE id = ?", [$pluginConfigId]);
        if (!$pluginConfig) {
            $this->flash('danger', 'S3 configuration not found.');
            $this->redirect("/clients/{$id}?tab=repos");
        }

        // Build repo path using same logic as store()
        $storageSetting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
        $storagePath = $storageSetting['value'] ?? '';
        $serverHost = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");
        $host = $serverHost['value'] ?? '';

        if (!empty($agent['ssh_unix_user']) && !empty($host)) {
            $path = SshKeyManager::buildSshRepoPath($agent['ssh_unix_user'], $host, $repoName);
        } else {
            $path = rtrim($storagePath, '/') . '/' . $id . '/' . $repoName;
        }

        // Create the repository record (encryption unknown, will be detected after restore)
        $repoId = $this->db->insert('repositories', [
            'agent_id' => $id,
            'name' => $repoName,
            'path' => $path,
            'encryption' => 'unknown',  // Will be detected by borg after restore
            'passphrase_encrypted' => null,  // Unknown for orphan repos
        ]);

        // Create local directory via SSH helper
        $localPath = BorgCommandBuilder::getLocalRepoPath(['path' => $path, 'agent_id' => $id, 'name' => $repoName]);
        $helperCmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'create-repo-dir', $localPath];
        exec(implode(' ', array_map('escapeshellarg', $helperCmd)) . ' 2>&1', $helperOutput, $helperRet);
        if ($helperRet !== 0) {
            $this->db->insert('server_log', [
                'agent_id' => $id,
                'level' => 'warning',
                'message' => "create-repo-dir helper failed for orphan restore: " . implode(' ', $helperOutput),
            ]);
        }

        // Queue the S3 restore job
        $jobId = $this->db->insert('backup_jobs', [
            'agent_id' => $id,
            'repository_id' => $repoId,
            'task_type' => 's3_restore',
            'plugin_config_id' => $pluginConfigId,
            'status' => 'queued',
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $id,
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => "Restoring orphan repository \"{$repoName}\" from S3 — job #{$jobId} queued",
        ]);

        $this->flash('success', "Repository \"{$repoName}\" created and S3 restore queued.");
        $this->redirect("/clients/{$id}?tab=repos");
    }

    /**
     * Enable or update S3 sync configuration for a repository.
     */
    public function s3Config(int $agentId, int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $repo = $this->db->fetchOne("SELECT * FROM repositories WHERE id = ? AND agent_id = ?", [$id, $agentId]);
        if (!$repo || !$this->canAccessAgent($agentId)) {
            $this->flash('danger', 'Repository not found.');
            $this->redirect('/clients');
        }

        $this->requirePermission(PermissionService::MANAGE_REPOS, $agentId);

        $pluginConfigId = (int) ($_POST['plugin_config_id'] ?? 0);
        if ($pluginConfigId === 0) {
            $this->flash('danger', 'Please select an S3 configuration.');
            $this->redirect("/clients/{$agentId}/repo/{$id}");
        }

        // Verify the plugin config exists and belongs to this agent
        $pluginConfig = $this->db->fetchOne(
            "SELECT pc.id, pc.name FROM plugin_configs pc
             JOIN plugins p ON p.id = pc.plugin_id
             WHERE pc.id = ? AND pc.agent_id = ? AND p.slug = 's3_sync'",
            [$pluginConfigId, $agentId]
        );
        if (!$pluginConfig) {
            $this->flash('danger', 'Invalid S3 configuration.');
            $this->redirect("/clients/{$agentId}/repo/{$id}");
        }

        // Check if config already exists for this repo
        $existing = $this->db->fetchOne(
            "SELECT id FROM repository_s3_configs WHERE repository_id = ?",
            [$id]
        );

        if ($existing) {
            // Update existing config
            $this->db->update('repository_s3_configs', [
                'plugin_config_id' => $pluginConfigId,
                'enabled' => 1,
            ], 'id = ?', [$existing['id']]);
        } else {
            // Create new config
            $this->db->insert('repository_s3_configs', [
                'repository_id' => $id,
                'plugin_config_id' => $pluginConfigId,
                'enabled' => 1,
            ]);
        }

        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'level' => 'info',
            'message' => "S3 sync enabled for repository \"{$repo['name']}\" using config \"{$pluginConfig['name']}\"",
        ]);

        $this->flash('success', "S3 sync enabled for repository \"{$repo['name']}\".");
        $this->redirect("/clients/{$agentId}/repo/{$id}");
    }

    /**
     * Disable S3 sync for a repository (data remains in S3).
     */
    public function s3ConfigDelete(int $agentId, int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $repo = $this->db->fetchOne("SELECT * FROM repositories WHERE id = ? AND agent_id = ?", [$id, $agentId]);
        if (!$repo || !$this->canAccessAgent($agentId)) {
            $this->flash('danger', 'Repository not found.');
            $this->redirect('/clients');
        }

        $this->requirePermission(PermissionService::MANAGE_REPOS, $agentId);

        // Delete the S3 config (data remains in S3 bucket)
        $this->db->delete('repository_s3_configs', 'repository_id = ?', [$id]);

        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'level' => 'info',
            'message' => "S3 sync disabled for repository \"{$repo['name']}\" (data remains in S3)",
        ]);

        $this->flash('success', "S3 sync disabled for repository \"{$repo['name']}\". Data remains in S3.");
        $this->redirect("/clients/{$agentId}/repo/{$id}");
    }
}

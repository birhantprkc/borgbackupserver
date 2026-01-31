<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\SshKeyManager;
use BBS\Services\Encryption;

class ClientController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        $where = '1=1';
        $params = [];

        if (!$this->isAdmin()) {
            $where = 'a.user_id = ?';
            $params[] = $_SESSION['user_id'];
        }

        $agents = $this->db->fetchAll("
            SELECT a.*,
                   u.username as owner_name,
                   (SELECT COUNT(*) FROM repositories r WHERE r.agent_id = a.id) as repo_count,
                   (SELECT COUNT(*) FROM schedules s JOIN backup_plans bp ON bp.id = s.backup_plan_id WHERE bp.agent_id = a.id) as schedule_count,
                   (SELECT COALESCE(SUM(r2.size_bytes), 0) FROM repositories r2 WHERE r2.agent_id = a.id) as total_size,
                   (SELECT COUNT(*) FROM archives ar JOIN repositories r3 ON r3.id = ar.repository_id WHERE r3.agent_id = a.id) as restore_points
            FROM agents a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE {$where}
            ORDER BY a.id DESC
        ", $params);

        $this->view('clients/index', [
            'pageTitle' => 'Clients',
            'agents' => $agents,
        ]);
    }

    public function add(): void
    {
        $this->requireAdmin();

        $users = $this->db->fetchAll("SELECT id, username FROM users ORDER BY username");

        $this->view('clients/add', [
            'pageTitle' => 'Clients',
            'users' => $users,
        ]);
    }

    public function store(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $name = trim($_POST['name'] ?? '');
        if (empty($name)) {
            $this->flash('danger', 'Client name is required.');
            $this->redirect('/clients/add');
        }

        $apiKey = bin2hex(random_bytes(32));
        $userId = !empty($_POST['user_id']) ? (int) $_POST['user_id'] : null;

        $id = $this->db->insert('agents', [
            'name' => $name,
            'api_key' => $apiKey,
            'status' => 'setup',
            'user_id' => $userId,
        ]);

        // Pre-flight checks before creating the client
        $storageSetting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
        $storagePath = $storageSetting['value'] ?? null;
        if (!$storagePath) {
            $this->db->delete('agents', 'id = ?', [$id]);
            $this->flash('danger', 'Cannot create client — no storage path configured. Go to Settings to set one.');
            $this->redirect('/clients/add');
        }

        // Create storage directory
        $clientDir = rtrim($storagePath, '/') . '/' . $id;
        if (!is_dir($clientDir) && !@mkdir($clientDir, 0755, true)) {
            $this->db->delete('agents', 'id = ?', [$id]);
            $this->flash('danger', "Cannot create client — failed to create storage directory: {$clientDir}. Check permissions on {$storagePath}.");
            $this->redirect('/clients/add');
        }

        // Provision SSH access: create Unix user, SSH keys, authorized_keys
        $sshResult = SshKeyManager::provisionClient($id, $name, $storagePath);
        if (!$sshResult) {
            // Clean up: remove storage dir and agent record
            @rmdir($clientDir);
            $this->db->delete('agents', 'id = ?', [$id]);
            $this->flash('danger', 'Cannot create client — SSH provisioning failed. Ensure bbs-ssh-helper is installed at /usr/local/bin/bbs-ssh-helper with sudo access. See the Installation Guide: https://github.com/marcpope/borgbackupserver/blob/main/docs/INSTALL.md');
            $this->redirect('/clients/add');
        }

        $this->db->insert('server_log', [
            'agent_id' => $id,
            'level' => 'info',
            'message' => "Client created. SSH provisioned: user {$sshResult['unix_user']}, home {$sshResult['home_dir']}",
        ]);

        $this->flash('success', 'Client created. Install the agent using the command below.');
        $this->redirect("/clients/{$id}");
    }

    public function detail(int $id): void
    {
        $this->requireAuth();

        $agent = $this->getAgent($id);
        if (!$agent) {
            $this->flash('danger', 'Client not found.');
            $this->redirect('/clients');
        }

        $repositories = $this->db->fetchAll("
            SELECT r.*
            FROM repositories r
            WHERE r.agent_id = ?
            ORDER BY r.id DESC
        ", [$id]);

        $plans = $this->db->fetchAll("
            SELECT bp.*, r.name as repo_name, s.frequency, s.times, s.day_of_week, s.day_of_month, s.enabled as schedule_enabled, s.id as schedule_id
            FROM backup_plans bp
            LEFT JOIN repositories r ON r.id = bp.repository_id
            LEFT JOIN schedules s ON s.backup_plan_id = bp.id
            WHERE bp.agent_id = ?
            ORDER BY bp.id DESC
        ", [$id]);

        $recentJobs = $this->db->fetchAll("
            SELECT bj.*, r.name as repo_name
            FROM backup_jobs bj
            LEFT JOIN repositories r ON r.id = bj.repository_id
            WHERE bj.agent_id = ?
            ORDER BY bj.queued_at DESC
            LIMIT 20
        ", [$id]);

        $serverHost = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");

        // Templates for schedules tab
        $templates = $this->db->fetchAll("SELECT * FROM backup_templates ORDER BY name");

        // Archives for restore tab
        $archives = $this->db->fetchAll("
            SELECT ar.*, r.name as repo_name
            FROM archives ar
            JOIN repositories r ON r.id = ar.repository_id
            WHERE r.agent_id = ?
            ORDER BY r.name ASC, ar.created_at DESC
        ", [$id]);

        // Summary stats for header
        $totalSize = array_sum(array_column($repositories, 'size_bytes'));
        $totalArchives = array_sum(array_column($repositories, 'archive_count'));
        $lastJob = $this->db->fetchOne("
            SELECT status, completed_at FROM backup_jobs
            WHERE agent_id = ? AND status IN ('completed','failed')
            ORDER BY completed_at DESC LIMIT 1
        ", [$id]);

        // Users list for owner assignment
        $users = $this->isAdmin() ? $this->db->fetchAll("SELECT id, username FROM users ORDER BY username") : [];

        // Status tab data
        $nextBackup = $this->db->fetchOne("
            SELECT s.next_run, bp.name as plan_name, bp.id as plan_id
            FROM schedules s
            JOIN backup_plans bp ON bp.id = s.backup_plan_id
            WHERE bp.agent_id = ? AND s.enabled = 1 AND s.next_run IS NOT NULL
            ORDER BY s.next_run ASC LIMIT 1
        ", [$id]);

        $jobStats = $this->db->fetchOne("
            SELECT
                COUNT(*) as total,
                SUM(status = 'completed') as completed,
                SUM(status = 'failed') as failed,
                AVG(CASE WHEN status = 'completed' THEN duration_seconds END) as avg_duration
            FROM (
                SELECT status, duration_seconds FROM backup_jobs
                WHERE agent_id = ? AND status IN ('completed','failed')
                ORDER BY completed_at DESC LIMIT 30
            ) recent
        ", [$id]);

        $recentErrors = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM backup_jobs WHERE agent_id = ? AND status = 'failed' AND completed_at > DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$id]
        );

        $durationChart = $this->db->fetchAll("
            SELECT DATE_FORMAT(completed_at, '%b %d %H:%i') as label,
                   duration_seconds, status, task_type
            FROM backup_jobs
            WHERE agent_id = ? AND status IN ('completed','failed') AND completed_at IS NOT NULL
            ORDER BY completed_at DESC LIMIT 30
        ", [$id]);

        // Plugins for schedules tab
        $pluginManager = new \BBS\Services\PluginManager();
        $agentPlugins = $pluginManager->getAgentPlugins($id);
        $allPlugins = $pluginManager->getAllPlugins();

        $this->view('clients/detail', [
            'pageTitle' => 'Clients',
            'agent' => $agent,
            'totalSize' => $totalSize,
            'totalArchives' => $totalArchives,
            'lastJob' => $lastJob,
            'users' => $users,
            'repositories' => $repositories,
            'plans' => $plans,
            'recentJobs' => $recentJobs,
            'serverHost' => $serverHost['value'] ?? 'YOUR_SERVER_HOST',
            'archives' => $archives,
            'templates' => $templates,
            'nextBackup' => $nextBackup,
            'jobStats' => $jobStats,
            'recentErrors' => (int) ($recentErrors['cnt'] ?? 0),
            'durationChart' => array_reverse($durationChart),
            'agentPlugins' => $agentPlugins,
            'allPlugins' => $allPlugins,
            'pluginManager' => $pluginManager,
        ]);
    }

    public function repos(int $id): void
    {
        $this->detail($id);
    }

    public function schedules(int $id): void
    {
        $this->detail($id);
    }

    public function restore(int $id): void
    {
        $this->detail($id);
    }

    /**
     * GET /clients/{id}/catalog/{archive_id}
     * Returns paginated file catalog as JSON for AJAX.
     */
    public function catalog(int $id, int $archive_id): void
    {
        $this->requireAuth();

        $agent = $this->getAgent($id);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $search = trim($_GET['search'] ?? '');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 100;
        $offset = ($page - 1) * $perPage;

        $where = 'fc.archive_id = ? AND fp.agent_id = ?';
        $params = [$archive_id, $id];

        if ($search !== '') {
            $where .= ' AND (fp.file_name LIKE ? OR fp.path LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $total = $this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM file_catalog fc
             JOIN file_paths fp ON fp.id = fc.file_path_id
             WHERE {$where}",
            $params
        );

        $files = $this->db->fetchAll(
            "SELECT fp.id, fp.path as file_path, fp.file_name, fc.file_size, fc.status, fc.mtime
             FROM file_catalog fc
             JOIN file_paths fp ON fp.id = fc.file_path_id
             WHERE {$where}
             ORDER BY fp.path
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        $this->json([
            'files' => $files,
            'total' => (int) $total['cnt'],
            'page' => $page,
            'pages' => max(1, ceil($total['cnt'] / $perPage)),
        ]);
    }

    /**
     * GET /clients/{id}/catalog/{archive_id}/tree
     * Returns directory tree children for a given path prefix.
     */
    public function catalogTree(int $id, int $archive_id): void
    {
        $this->requireAuth();

        $agent = $this->getAgent($id);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $prefix = $_GET['path'] ?? '/';
        // Ensure prefix ends with /
        if ($prefix !== '/' && !str_ends_with($prefix, '/')) {
            $prefix .= '/';
        }

        $prefixLen = strlen($prefix);
        $likePath = str_replace(['%', '_'], ['\\%', '\\_'], $prefix) . '%';

        // Get subdirectories: distinct next path segment for paths that have more segments
        $dirs = $this->db->fetchAll("
            SELECT
                SUBSTRING_INDEX(SUBSTRING(fp.path, ?), '/', 1) as name,
                COUNT(*) as file_count,
                SUM(fc.file_size) as total_size
            FROM file_catalog fc
            JOIN file_paths fp ON fp.id = fc.file_path_id
            WHERE fc.archive_id = ? AND fp.agent_id = ?
              AND fp.path LIKE ?
              AND LOCATE('/', SUBSTRING(fp.path, ?)) > 0
            GROUP BY name
            ORDER BY name
        ", [$prefixLen + 1, $archive_id, $id, $likePath, $prefixLen + 1]);

        // Build full paths for dirs
        $directories = [];
        foreach ($dirs as $d) {
            $directories[] = [
                'name' => $d['name'],
                'path' => $prefix . $d['name'] . '/',
                'file_count' => (int) $d['file_count'],
                'total_size' => (int) $d['total_size'],
            ];
        }

        // Get files directly at this level (no more / after prefix)
        $files = $this->db->fetchAll("
            SELECT fp.id, fp.path as file_path, fp.file_name, fc.file_size, fc.status
            FROM file_catalog fc
            JOIN file_paths fp ON fp.id = fc.file_path_id
            WHERE fc.archive_id = ? AND fp.agent_id = ?
              AND fp.path LIKE ?
              AND LOCATE('/', SUBSTRING(fp.path, ?)) = 0
            ORDER BY fp.file_name
        ", [$archive_id, $id, $likePath, $prefixLen + 1]);

        $this->json([
            'dirs' => $directories,
            'files' => $files,
            'path' => $prefix,
        ]);
    }

    /**
     * POST /clients/{id}/restore
     * Creates a restore job for selected files from an archive.
     */
    public function restoreSubmit(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $agent = $this->getAgent($id);
        if (!$agent) {
            $this->flash('danger', 'Client not found.');
            $this->redirect('/clients');
        }

        $archive_id = (int) ($_POST['archive_id'] ?? 0);
        $selectedFiles = $_POST['files'] ?? [];
        $destination = trim($_POST['destination'] ?? '');

        if (!$archive_id || empty($selectedFiles)) {
            $this->flash('danger', 'Select an archive and at least one file to restore.');
            $this->redirect("/clients/{$id}?tab=restore");
        }

        // Get archive and repo info
        $archive = $this->db->fetchOne("
            SELECT ar.*, r.path as repo_path, r.passphrase_encrypted
            FROM archives ar
            JOIN repositories r ON r.id = ar.repository_id
            WHERE ar.id = ? AND r.agent_id = ?
        ", [$archive_id, $id]);

        if (!$archive) {
            $this->flash('danger', 'Archive not found.');
            $this->redirect("/clients/{$id}?tab=restore");
        }

        // Check if repo already has an active job (borg can't run concurrent ops)
        $repoBusy = $this->db->fetchOne("
            SELECT id FROM backup_jobs
            WHERE repository_id = ?
              AND status IN ('queued', 'sent', 'running')
        ", [$archive['repository_id']]);

        if ($repoBusy) {
            $this->flash('warning', 'A job is already active for this repository. Wait for it to complete before starting a restore.');
            $this->redirect("/clients/{$id}?tab=restore");
            return;
        }

        // Create restore job
        $jobId = $this->db->insert('backup_jobs', [
            'agent_id' => $id,
            'backup_plan_id' => null,
            'repository_id' => $archive['repository_id'],
            'task_type' => 'restore',
            'status' => 'queued',
            'queued_at' => date('Y-m-d H:i:s'),
            'restore_archive_id' => $archive_id,
            'restore_paths' => json_encode($selectedFiles),
            'restore_destination' => $destination ?: null,
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $id,
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => "Restore queued: " . count($selectedFiles) . " paths from archive {$archive['archive_name']}",
        ]);

        $this->flash('success', 'Restore job queued. It will run when a slot is available.');
        $this->redirect("/clients/{$id}?tab=restore");
    }

    /**
     * POST /clients/{id}/download
     * Extracts selected paths from a borg archive on the server and streams as tar.gz.
     */
    public function download(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $agent = $this->getAgent($id);
        if (!$agent) {
            $this->flash('danger', 'Client not found.');
            $this->redirect('/clients');
        }

        $archive_id = (int) ($_POST['archive_id'] ?? 0);
        $selectedFiles = $_POST['files'] ?? [];

        if (!$archive_id || empty($selectedFiles)) {
            $this->flash('danger', 'Select an archive and at least one file to download.');
            $this->redirect("/clients/{$id}?tab=restore");
        }

        $archive = $this->db->fetchOne("
            SELECT ar.*, r.path as repo_path, r.passphrase_encrypted, r.encryption,
                   r.agent_id as repo_agent_id, r.name as repo_name
            FROM archives ar
            JOIN repositories r ON r.id = ar.repository_id
            WHERE ar.id = ? AND r.agent_id = ?
        ", [$archive_id, $id]);

        if (!$archive) {
            $this->flash('danger', 'Archive not found.');
            $this->redirect("/clients/{$id}?tab=restore");
        }

        // Build environment — server-side execution uses local paths
        $repo = [
            'path' => $archive['repo_path'],
            'passphrase_encrypted' => $archive['passphrase_encrypted'],
            'encryption' => $archive['encryption'],
            'agent_id' => $archive['repo_agent_id'] ?? $id,
            'name' => $archive['repo_name'] ?? '',
        ];
        $localPath = \BBS\Services\BorgCommandBuilder::getLocalRepoPath($repo);
        $env = \BBS\Services\BorgCommandBuilder::buildEnv($repo, false);

        // Create temp directory for extraction
        $tmpDir = sys_get_temp_dir() . '/bbs-download-' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0700, true);

        try {
            // Build borg extract args: repo::archive + selected paths
            $borgArgs = [$localPath . '::' . $archive['archive_name']];
            foreach ($selectedFiles as $path) {
                $path = ltrim($path, '/');
                if ($path !== '') {
                    $borgArgs[] = rtrim($path, '/');
                }
            }

            // Use SSH helper to run borg extract as the repo-owning user
            // (www-data can't read repo files owned by the bbs-* user)
            $sshUser = $agent['ssh_unix_user'] ?? '';
            if (!empty($sshUser)) {
                // Pass passphrase as argument (sudo strips env vars)
                $passphrase = $env['BORG_PASSPHRASE'] ?? '';
                $cmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'borg-extract', $sshUser, $tmpDir, $passphrase];
                $cmd = array_merge($cmd, $borgArgs);

                $envStrings = null; // helper handles env; null inherits current env (for PATH)
            } else {
                // Fallback: run directly as www-data (non-SSH repos)
                $cmd = array_merge(['borg', 'extract'], $borgArgs);
                $envStrings = [];
                foreach ($_SERVER as $k => $v) {
                    if (is_string($v)) $envStrings[$k] = $v;
                }
                foreach ($env as $k => $v) {
                    $envStrings[$k] = $v;
                }
            }

            $desc = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $proc = proc_open($cmd, $desc, $pipes, $tmpDir, $envStrings);
            if (!is_resource($proc)) {
                throw new \RuntimeException('Failed to run borg extract');
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);

            if ($exitCode > 1) {
                throw new \RuntimeException('borg extract failed: ' . trim($stdout . "\n" . $stderr));
            }

            // Check if anything was extracted
            $extractedFiles = [];
            $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($rii as $file) {
                if ($file->isFile()) {
                    $extractedFiles[] = $file->getPathname();
                }
            }

            if (empty($extractedFiles)) {
                throw new \RuntimeException('No files were extracted');
            }

            // Generate filename
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $agent['name'] . '-' . $archive['archive_name']);

            // Stream as tar.gz
            header('Content-Type: application/gzip');
            header('Content-Disposition: attachment; filename="' . $safeName . '.tar.gz"');
            header('Cache-Control: no-cache');

            $tarCmd = ['tar', 'czf', '-', '-C', $tmpDir, '.'];
            $tarProc = proc_open($tarCmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tarPipes);
            if (!is_resource($tarProc)) {
                throw new \RuntimeException('Failed to create tar archive');
            }

            fclose($tarPipes[0]);
            fpassthru($tarPipes[1]);
            fclose($tarPipes[1]);
            fclose($tarPipes[2]);
            proc_close($tarProc);

        } catch (\Exception $e) {
            $this->flash('danger', 'Download failed: ' . $e->getMessage());
            $this->redirect("/clients/{$id}?tab=restore");
        } finally {
            // Cleanup temp directory
            $this->removeDir($tmpDir);
        }

        exit;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    public function update(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $agent = $this->getAgent($id);
        if (!$agent) {
            $this->flash('danger', 'Client not found.');
            $this->redirect('/clients');
        }

        $data = [];
        if (isset($_POST['name']) && trim($_POST['name']) !== '') {
            $data['name'] = trim($_POST['name']);
        }

        if (!empty($data)) {
            $this->db->update('agents', $data, 'id = ?', [$id]);
            $this->flash('success', 'Client updated.');
        }

        $this->redirect("/clients/{$id}");
    }

    public function delete(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $agent = $this->getAgent($id);
        if (!$agent) {
            $this->flash('danger', 'Client not found.');
            $this->redirect('/clients');
        }

        // Deprovision SSH user
        if (!empty($agent['ssh_unix_user'])) {
            SshKeyManager::deprovisionClient($agent['ssh_unix_user']);
        }

        $this->db->delete('agents', 'id = ?', [$id]);
        $this->flash('success', "Client \"{$agent['name']}\" deleted.");
        $this->redirect('/clients');
    }

    private function getAgent(int $id): ?array
    {
        $agent = $this->db->fetchOne("
            SELECT a.*, u.username as owner_name
            FROM agents a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE a.id = ?
        ", [$id]);

        if (!$agent) {
            return null;
        }

        if (!$this->isAdmin() && $agent['user_id'] != $_SESSION['user_id']) {
            return null;
        }

        return $agent;
    }

    public function updateBorg(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $agent = $this->getAgent($id);
        if (!$agent) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        // Create an update_borg job
        $this->db->insert('backup_jobs', [
            'agent_id' => $id,
            'task_type' => 'update_borg',
            'status' => 'queued',
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $id,
            'level' => 'info',
            'message' => 'Borg update requested by ' . ($_SESSION['username'] ?? 'unknown'),
        ]);

        $this->flash('success', 'Borg update job queued for ' . $agent['name']);
        $this->redirect("/clients/{$id}");
    }

    public function updateAgent(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $agent = $this->getAgent($id);
        if (!$agent) {
            http_response_code(404);
            echo 'Not found';
            return;
        }

        $this->db->insert('backup_jobs', [
            'agent_id' => $id,
            'task_type' => 'update_agent',
            'status' => 'queued',
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $id,
            'level' => 'info',
            'message' => 'Agent update requested by ' . ($_SESSION['username'] ?? 'unknown'),
        ]);

        $this->flash('success', 'Agent update job queued for ' . $agent['name']);
        $this->redirect("/clients/{$id}");
    }
}

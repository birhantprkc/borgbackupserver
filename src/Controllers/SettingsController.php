<?php

namespace BBS\Controllers;

use BBS\Core\Controller;

class SettingsController extends Controller
{
    public function index(): void
    {
        $this->requireAdmin();

        $settings = [];
        $rows = $this->db->fetchAll("SELECT `key`, `value` FROM settings");
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }

        $templates = $this->db->fetchAll("SELECT * FROM backup_templates ORDER BY name");

        $this->view('settings/index', [
            'pageTitle' => 'Settings',
            'settings' => $settings,
            'templates' => $templates,
        ]);
    }

    public function update(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $allowed = ['max_queue', 'server_host', 'agent_poll_interval', 'session_timeout_hours', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from', 'notification_retention_days', 'storage_alert_threshold', 'storage_path'];

        foreach ($allowed as $key) {
            if (isset($_POST[$key])) {
                $existing = $this->db->fetchOne("SELECT `key` FROM settings WHERE `key` = ?", [$key]);
                if ($existing) {
                    $this->db->update('settings', ['value' => $_POST[$key]], "`key` = ?", [$key]);
                } else {
                    $this->db->insert('settings', ['key' => $key, 'value' => $_POST[$key]]);
                }
            }
        }

        // Checkbox toggles: unchecked = not posted, so explicitly save '0'
        $checkboxKeys = ['email_on_backup_failed', 'email_on_agent_offline', 'email_on_storage_low', 'email_on_missed_schedule'];
        foreach ($checkboxKeys as $key) {
            $value = isset($_POST[$key]) ? '1' : '0';
            $existing = $this->db->fetchOne("SELECT `key` FROM settings WHERE `key` = ?", [$key]);
            if ($existing) {
                $this->db->update('settings', ['value' => $value], "`key` = ?", [$key]);
            } else {
                $this->db->insert('settings', ['key' => $key, 'value' => $value]);
            }
        }

        // Update APP_URL in .env when server_host or SSL toggle changes
        if (isset($_POST['server_host'])) {
            $protocol = isset($_POST['enable_ssl']) ? 'https' : 'http';
            $host = trim($_POST['server_host']);
            $newAppUrl = "{$protocol}://{$host}";
            $envPath = dirname(__DIR__, 2) . '/config/.env';
            if (file_exists($envPath) && is_writable($envPath)) {
                $env = file_get_contents($envPath);
                $env = preg_replace('/^APP_URL=.*$/m', 'APP_URL=' . $newAppUrl, $env);
                file_put_contents($envPath, $env);
            }
        }

        $this->flash('success', 'Settings updated.');
        $tab = $_POST['_tab'] ?? 'general';
        $this->redirect('/settings?tab=' . urlencode($tab));
    }

    public function addTemplate(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $directories = trim($_POST['directories'] ?? '');
        $excludes = trim($_POST['excludes'] ?? '');

        if (empty($name) || empty($directories)) {
            $this->flash('danger', 'Template name and directories are required.');
            $this->redirect('/settings?tab=templates');
        }

        $this->db->insert('backup_templates', [
            'name' => $name,
            'description' => $description,
            'directories' => $directories,
            'excludes' => $excludes ?: null,
        ]);

        $this->flash('success', "Template \"{$name}\" created.");
        $this->redirect('/settings?tab=templates');
    }

    public function editTemplate(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $directories = trim($_POST['directories'] ?? '');
        $excludes = trim($_POST['excludes'] ?? '');

        if (empty($name) || empty($directories)) {
            $this->flash('danger', 'Template name and directories are required.');
            $this->redirect('/settings?tab=templates');
        }

        $this->db->update('backup_templates', [
            'name' => $name,
            'description' => $description,
            'directories' => $directories,
            'excludes' => $excludes ?: null,
        ], 'id = ?', [$id]);

        $this->flash('success', "Template \"{$name}\" updated.");
        $this->redirect('/settings?tab=templates');
    }

    public function deleteTemplate(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $this->db->delete('backup_templates', 'id = ?', [$id]);
        $this->flash('success', 'Template deleted.');
        $this->redirect('/settings?tab=templates');
    }

    public function checkUpdate(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $service = new \BBS\Services\UpdateService();
        $result = $service->checkForUpdate();

        if (isset($result['error'])) {
            $this->flash('danger', 'Update check failed: ' . $result['error']);
        } elseif (!empty($result['message'])) {
            $this->flash('info', $result['message']);
        } elseif ($result['update_available']) {
            $this->flash('success', 'Update available: v' . $result['version']);
        } else {
            $this->flash('success', 'You are running the latest version (v' . $result['current'] . ').');
        }

        $this->redirect('/settings?tab=updates');
    }

    public function upgrade(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $service = new \BBS\Services\UpdateService();
        $result = $service->performUpgrade();

        // Store result in session so the view can display it
        $_SESSION['upgrade_result'] = $result;

        if ($result['success']) {
            $this->flash('success', 'Upgrade completed successfully.');
        } else {
            $this->flash('danger', 'Upgrade failed. See log below.');
        }

        $this->redirect('/settings?tab=updates');
    }

    public function sync(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $projectRoot = dirname(__DIR__, 2);
        $updateScript = $projectRoot . '/bin/bbs-update';

        $lines = [];
        $code = 0;
        exec("sudo " . escapeshellarg($updateScript) . " " . escapeshellarg($projectRoot) . " main 2>&1", $lines, $code);

        $_SESSION['upgrade_result'] = [
            'success' => $code === 0,
            'log' => $lines,
        ];

        if ($code === 0) {
            $this->flash('success', 'Sync completed — code updated and permissions fixed.');
        } else {
            $this->flash('danger', 'Sync failed. See log below.');
        }

        $this->redirect('/settings?tab=updates');
    }

    /**
     * Queue agent updates for all outdated agents.
     * POST /settings/upgrade-agents
     */
    public function upgradeAgents(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        // Read bundled agent version
        $serverAgentVersion = null;
        $agentFile = dirname(__DIR__, 2) . '/agent/bbs-agent.py';
        if (file_exists($agentFile)) {
            $handle = fopen($agentFile, 'r');
            if ($handle) {
                for ($i = 0; $i < 50 && ($line = fgets($handle)) !== false; $i++) {
                    if (preg_match('/^AGENT_VERSION\s*=\s*["\']([^"\']+)["\']/m', $line, $m)) {
                        $serverAgentVersion = $m[1];
                        break;
                    }
                }
                fclose($handle);
            }
        }

        if (!$serverAgentVersion) {
            $this->flash('danger', 'Could not determine bundled agent version.');
            $this->redirect('/settings?tab=updates');
        }

        // Find outdated agents
        $outdated = $this->db->fetchAll(
            "SELECT id, name FROM agents WHERE agent_version IS NOT NULL AND agent_version != ?",
            [$serverAgentVersion]
        );

        // Find agents that already have a pending update job
        $pending = $this->db->fetchAll(
            "SELECT agent_id FROM backup_jobs WHERE task_type = 'update_agent' AND status IN ('queued', 'sent', 'running')"
        );
        $pendingIds = array_column($pending, 'agent_id');

        $queued = 0;
        foreach ($outdated as $agent) {
            if (in_array($agent['id'], $pendingIds)) {
                continue;
            }
            $jobId = $this->db->insert('backup_jobs', [
                'agent_id' => $agent['id'],
                'task_type' => 'update_agent',
                'status' => 'queued',
            ]);
            $this->db->insert('server_log', [
                'agent_id' => $agent['id'],
                'backup_job_id' => $jobId,
                'level' => 'info',
                'message' => "Agent update queued (bulk) to v{$serverAgentVersion}",
            ]);
            $queued++;
        }

        if ($queued > 0) {
            $this->flash('success', "Queued agent updates for {$queued} client(s).");
        } else {
            $this->flash('info', 'No agents need updating (or updates already queued).');
        }

        $this->redirect('/settings?tab=updates');
    }

    /**
     * GET /api/templates/{id} — returns template data as JSON for form pre-fill.
     */
    public function templateJson(int $id): void
    {
        $this->requireAuth();

        $template = $this->db->fetchOne("SELECT * FROM backup_templates WHERE id = ?", [$id]);
        if (!$template) {
            $this->json(['error' => 'Not found'], 404);
        }

        $this->json($template);
    }
}

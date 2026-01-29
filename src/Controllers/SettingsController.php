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

        $storageLocations = $this->db->fetchAll("SELECT * FROM storage_locations ORDER BY id");
        $templates = $this->db->fetchAll("SELECT * FROM backup_templates ORDER BY name");

        $this->view('settings/index', [
            'pageTitle' => 'Settings',
            'settings' => $settings,
            'storageLocations' => $storageLocations,
            'templates' => $templates,
        ]);
    }

    public function update(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $allowed = ['max_queue', 'server_host', 'agent_poll_interval', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from', 'notification_retention_days', 'storage_alert_threshold'];

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

        $this->flash('success', 'Settings updated.');
        $tab = $_POST['_tab'] ?? 'general';
        $this->redirect('/settings?tab=' . urlencode($tab));
    }

    public function addStorage(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $label = trim($_POST['label'] ?? '');
        $path = trim($_POST['path'] ?? '');
        $maxSizeGb = !empty($_POST['max_size_gb']) ? (int) $_POST['max_size_gb'] : null;
        $isDefault = isset($_POST['is_default']) ? 1 : 0;

        if (empty($label) || empty($path)) {
            $this->flash('danger', 'Label and path are required.');
            $this->redirect('/settings?tab=storage');
        }

        if ($isDefault) {
            $this->db->update('storage_locations', ['is_default' => 0], '1=1');
        }

        $this->db->insert('storage_locations', [
            'label' => $label,
            'path' => $path,
            'max_size_gb' => $maxSizeGb,
            'is_default' => $isDefault,
        ]);

        $this->flash('success', "Storage location \"{$label}\" added.");
        $this->redirect('/settings?tab=storage');
    }

    public function deleteStorage(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $this->db->delete('storage_locations', 'id = ?', [$id]);
        $this->flash('success', 'Storage location removed.');
        $this->redirect('/settings?tab=storage');
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

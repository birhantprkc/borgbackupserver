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

        $allowed = ['max_queue', 'server_host', 'agent_poll_interval', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from'];

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

        $this->flash('success', 'Settings updated.');
        $this->redirect('/settings');
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
            $this->redirect('/settings');
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
        $this->redirect('/settings');
    }

    public function deleteStorage(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $this->db->delete('storage_locations', 'id = ?', [$id]);
        $this->flash('success', 'Storage location removed.');
        $this->redirect('/settings');
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
            $this->redirect('/settings#templates');
        }

        $this->db->insert('backup_templates', [
            'name' => $name,
            'description' => $description,
            'directories' => $directories,
            'excludes' => $excludes ?: null,
        ]);

        $this->flash('success', "Template \"{$name}\" created.");
        $this->redirect('/settings#templates');
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
            $this->redirect('/settings#templates');
        }

        $this->db->update('backup_templates', [
            'name' => $name,
            'description' => $description,
            'directories' => $directories,
            'excludes' => $excludes ?: null,
        ], 'id = ?', [$id]);

        $this->flash('success', "Template \"{$name}\" updated.");
        $this->redirect('/settings#templates');
    }

    public function deleteTemplate(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $this->db->delete('backup_templates', 'id = ?', [$id]);
        $this->flash('success', 'Template deleted.');
        $this->redirect('/settings#templates');
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

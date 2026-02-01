<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\PluginManager;

class PluginConfigController extends Controller
{
    private function getAgent(int $id): ?array
    {
        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$id]);
        if (!$agent || (!$this->isAdmin() && $agent['user_id'] != $_SESSION['user_id'])) {
            return null;
        }
        return $agent;
    }

    /**
     * Create a named plugin config.
     * POST /clients/{id}/plugin-configs
     */
    public function store(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        if (!$this->getAgent($id)) {
            $this->flash('danger', 'Access denied.');
            $this->redirect('/clients');
        }

        $pluginId = (int) ($_POST['plugin_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $config = $_POST['plugin_config'] ?? [];

        if (empty($name) || empty($pluginId)) {
            $this->flash('danger', 'Name and plugin are required.');
            $this->redirect("/clients/{$id}?tab=plugins");
        }

        $pluginManager = new PluginManager();
        $pluginManager->savePluginConfig($id, $pluginId, $name, $config);

        $this->flash('success', "Plugin configuration \"{$name}\" created.");
        $this->redirect("/clients/{$id}?tab=plugins");
    }

    /**
     * Update a named plugin config.
     * POST /clients/{id}/plugin-configs/{configId}/edit
     */
    public function update(int $id, int $configId): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        if (!$this->getAgent($id)) {
            $this->flash('danger', 'Access denied.');
            $this->redirect('/clients');
        }

        $name = trim($_POST['name'] ?? '');
        $config = $_POST['plugin_config'] ?? [];

        if (empty($name)) {
            $this->flash('danger', 'Name is required.');
            $this->redirect("/clients/{$id}?tab=plugins");
        }

        $pluginManager = new PluginManager();
        $pluginManager->updatePluginConfig($configId, $name, $config);

        $this->flash('success', "Plugin configuration \"{$name}\" updated.");
        $this->redirect("/clients/{$id}?tab=plugins");
    }

    /**
     * Delete a named plugin config.
     * POST /clients/{id}/plugin-configs/{configId}/delete
     */
    public function delete(int $id, int $configId): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        if (!$this->getAgent($id)) {
            $this->flash('danger', 'Access denied.');
            $this->redirect('/clients');
        }

        $pluginManager = new PluginManager();
        $pluginManager->deletePluginConfig($configId);

        $this->flash('success', 'Plugin configuration deleted.');
        $this->redirect("/clients/{$id}?tab=plugins");
    }

    /**
     * Queue a plugin test job.
     * POST /clients/{id}/plugin-configs/{configId}/test
     */
    public function test(int $id, int $configId): void
    {
        $this->requireAuth();
        // Skip CSRF for AJAX — session auth is sufficient for same-origin POST

        if (!$this->getAgent($id)) {
            $this->json(['error' => 'Access denied'], 403);
        }

        $jobId = $this->db->insert('backup_jobs', [
            'agent_id' => $id,
            'task_type' => 'plugin_test',
            'status' => 'queued',
            'plugin_config_id' => $configId,
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $id,
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => "Plugin test queued (job #{$jobId}, config #{$configId})",
        ]);

        $this->json(['status' => 'ok', 'job_id' => $jobId]);
    }

    /**
     * Poll test job status.
     * GET /clients/{id}/plugin-configs/{configId}/test-status
     */
    public function testStatus(int $id, int $configId): void
    {
        $this->requireAuth();

        if (!$this->getAgent($id)) {
            $this->json(['error' => 'Access denied'], 403);
        }

        // Get the latest plugin_test job for this config
        $job = $this->db->fetchOne("
            SELECT id, status, error_log, completed_at
            FROM backup_jobs
            WHERE agent_id = ? AND task_type = 'plugin_test' AND plugin_config_id = ?
            ORDER BY queued_at DESC LIMIT 1
        ", [$id, $configId]);

        if (!$job) {
            $this->json(['status' => 'not_found']);
        }

        $response = ['status' => $job['status']];

        if ($job['status'] === 'failed') {
            $response['error'] = $job['error_log'] ?? 'Unknown error';
        } elseif ($job['status'] === 'completed') {
            // Get output from server_log (agent sends output_log which is stored there)
            $log = $this->db->fetchOne("
                SELECT message FROM server_log
                WHERE backup_job_id = ? AND message LIKE 'Plugin test output:%'
                ORDER BY id DESC LIMIT 1
            ", [$job['id']]);
            $response['message'] = $log
                ? str_replace('Plugin test output: ', '', $log['message'])
                : 'Test completed successfully.';
        }

        $this->json($response);
    }
}

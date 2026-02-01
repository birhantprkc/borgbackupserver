<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\PluginManager;

class PluginController extends Controller
{
    /**
     * Enable/disable plugins for a client.
     * POST /clients/{id}/plugins
     */
    public function updateAgentPlugins(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$id]);
        if (!$agent || (!$this->isAdmin() && $agent['user_id'] != $_SESSION['user_id'])) {
            $this->flash('danger', 'Access denied.');
            $this->redirect('/clients');
        }

        $pluginManager = new PluginManager();
        $selectedPlugins = array_map('intval', $_POST['plugins'] ?? []);
        $allPlugins = $pluginManager->getAllPlugins();

        foreach ($allPlugins as $plugin) {
            $enabled = in_array($plugin['id'], $selectedPlugins);
            $pluginManager->setAgentPlugin($id, $plugin['id'], $enabled);
        }

        $this->flash('success', 'Plugin settings updated.');
        $this->redirect("/clients/{$id}?tab=plugins");
    }
}

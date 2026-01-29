<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\Encryption;

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
        $storageLocationId = !empty($_POST['storage_location_id']) ? (int) $_POST['storage_location_id'] : null;

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

        // Build repo path from storage location
        $path = '';
        if ($storageLocationId) {
            $loc = $this->db->fetchOne("SELECT * FROM storage_locations WHERE id = ?", [$storageLocationId]);
            if ($loc) {
                $path = rtrim($loc['path'], '/') . '/' . $agentId . '/' . $name;
            }
        }

        // Auto-generate passphrase if not provided and encryption is enabled
        if (empty($passphrase) && $encryption !== 'none') {
            $passphrase = $this->generatePassphrase();
        }

        $this->db->insert('repositories', [
            'agent_id' => $agentId,
            'storage_location_id' => $storageLocationId,
            'name' => $name,
            'path' => $path,
            'encryption' => $encryption,
            'passphrase_encrypted' => $encryption !== 'none' ? Encryption::encrypt($passphrase) : null,
        ]);

        $this->flash('success', "Repository \"{$name}\" created.");
        $this->redirect("/clients/{$agentId}");
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
        $this->db->delete('repositories', 'id = ?', [$id]);
        $this->flash('success', "Repository \"{$repo['name']}\" deleted.");
        $this->redirect("/clients/{$agentId}");
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

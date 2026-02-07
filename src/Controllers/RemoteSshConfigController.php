<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\Encryption;
use BBS\Services\RemoteSshService;

class RemoteSshConfigController extends Controller
{
    /**
     * Create a new remote SSH config.
     */
    public function store(): void
    {
        $this->requireAuth();
        $this->requireAdmin();
        $this->verifyCsrf();

        $name = trim($_POST['name'] ?? '');
        $remoteHost = trim($_POST['remote_host'] ?? '');
        $remotePort = (int) ($_POST['remote_port'] ?? 22);
        $remoteUser = trim($_POST['remote_user'] ?? '');
        $remoteBasePath = trim($_POST['remote_base_path'] ?? './');
        $sshPrivateKey = trim($_POST['ssh_private_key'] ?? '');
        $borgRemotePath = trim($_POST['borg_remote_path'] ?? '') ?: null;
        $appendRepoName = isset($_POST['append_repo_name']) ? 1 : 0;

        if (empty($name) || empty($remoteHost) || empty($remoteUser) || empty($sshPrivateKey)) {
            $this->flash('danger', 'Name, host, user, and SSH private key are required.');
            $this->redirect('/settings?tab=storage&section=remote');
        }

        if ($remotePort < 1 || $remotePort > 65535) {
            $remotePort = 22;
        }

        if (empty($remoteBasePath)) {
            $remoteBasePath = './';
        }

        $this->db->insert('remote_ssh_configs', [
            'name' => $name,
            'remote_host' => $remoteHost,
            'remote_port' => $remotePort,
            'remote_user' => $remoteUser,
            'remote_base_path' => $remoteBasePath,
            'ssh_private_key_encrypted' => Encryption::encrypt($sshPrivateKey),
            'borg_remote_path' => $borgRemotePath,
            'append_repo_name' => $appendRepoName,
        ]);

        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => "Remote SSH config \"{$name}\" created ({$remoteUser}@{$remoteHost})",
        ]);

        $this->flash('success', "Remote SSH host \"{$name}\" created.");
        $this->redirect('/settings?tab=storage&section=remote');
    }

    /**
     * Update an existing remote SSH config.
     */
    public function update(int $id): void
    {
        $this->requireAuth();
        $this->requireAdmin();
        $this->verifyCsrf();

        $existing = $this->db->fetchOne("SELECT * FROM remote_ssh_configs WHERE id = ?", [$id]);
        if (!$existing) {
            $this->flash('danger', 'Remote SSH config not found.');
            $this->redirect('/settings?tab=storage&section=remote');
        }

        $name = trim($_POST['name'] ?? '');
        $remoteHost = trim($_POST['remote_host'] ?? '');
        $remotePort = (int) ($_POST['remote_port'] ?? 22);
        $remoteUser = trim($_POST['remote_user'] ?? '');
        $remoteBasePath = trim($_POST['remote_base_path'] ?? './');
        $sshPrivateKey = trim($_POST['ssh_private_key'] ?? '');
        $borgRemotePath = trim($_POST['borg_remote_path'] ?? '') ?: null;
        $appendRepoName = isset($_POST['append_repo_name']) ? 1 : 0;

        if (empty($name) || empty($remoteHost) || empty($remoteUser)) {
            $this->flash('danger', 'Name, host, and user are required.');
            $this->redirect('/settings?tab=storage&section=remote');
        }

        if ($remotePort < 1 || $remotePort > 65535) {
            $remotePort = 22;
        }

        if (empty($remoteBasePath)) {
            $remoteBasePath = './';
        }

        $data = [
            'name' => $name,
            'remote_host' => $remoteHost,
            'remote_port' => $remotePort,
            'remote_user' => $remoteUser,
            'remote_base_path' => $remoteBasePath,
            'borg_remote_path' => $borgRemotePath,
            'append_repo_name' => $appendRepoName,
        ];

        // Only update SSH key if a new one was provided
        if (!empty($sshPrivateKey)) {
            $data['ssh_private_key_encrypted'] = Encryption::encrypt($sshPrivateKey);
        }

        $this->db->update('remote_ssh_configs', $data, 'id = ?', [$id]);

        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => "Remote SSH config \"{$name}\" updated",
        ]);

        $this->flash('success', "Remote SSH host \"{$name}\" updated.");
        $this->redirect('/settings?tab=storage&section=remote');
    }

    /**
     * Delete a remote SSH config (blocked if repos reference it).
     */
    public function delete(int $id): void
    {
        $this->requireAuth();
        $this->requireAdmin();
        $this->verifyCsrf();

        $config = $this->db->fetchOne("SELECT * FROM remote_ssh_configs WHERE id = ?", [$id]);
        if (!$config) {
            $this->flash('danger', 'Remote SSH config not found.');
            $this->redirect('/settings?tab=storage&section=remote');
        }

        $remoteSshService = new RemoteSshService();
        $repoCount = $remoteSshService->getRepoCount($id);
        if ($repoCount > 0) {
            $this->flash('danger', "Cannot delete \"{$config['name']}\" — {$repoCount} repository/ies still use this host. Delete or migrate them first.");
            $this->redirect('/settings?tab=storage&section=remote');
        }

        $this->db->delete('remote_ssh_configs', 'id = ?', [$id]);

        $this->db->insert('server_log', [
            'level' => 'info',
            'message' => "Remote SSH config \"{$config['name']}\" deleted",
        ]);

        $this->flash('success', "Remote SSH host \"{$config['name']}\" deleted.");
        $this->redirect('/settings?tab=storage&section=remote');
    }

    /**
     * Test connection to a remote SSH host.
     */
    public function test(int $id): void
    {
        $this->requireAuth();
        $this->requireAdmin();
        $this->verifyCsrf();

        $config = $this->db->fetchOne("SELECT * FROM remote_ssh_configs WHERE id = ?", [$id]);
        if (!$config) {
            $this->json(['success' => false, 'error' => 'Config not found']);
            return;
        }

        $remoteSshService = new RemoteSshService();
        $result = $remoteSshService->testConnection($config);

        if ($result['success']) {
            $this->json(['status' => 'ok', 'version' => $result['version'] ?? '']);
        } else {
            $this->json(['status' => 'error', 'error' => $result['error'] ?? 'Connection failed']);
        }
    }
}

<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\ServerStats;

class StorageLocationController extends Controller
{
    public function index(): void
    {
        $this->requireAdmin();

        $locations = $this->db->fetchAll("SELECT * FROM storage_locations ORDER BY is_default DESC, label");

        // Attach repo counts and disk usage to each location
        foreach ($locations as &$loc) {
            $loc['repo_count'] = (int) ($this->db->fetchOne(
                "SELECT COUNT(*) as cnt FROM repositories WHERE storage_location_id = ?",
                [$loc['id']]
            )['cnt'] ?? 0);

            $loc['total_size'] = (int) ($this->db->fetchOne(
                "SELECT COALESCE(SUM(size_bytes), 0) as total FROM repositories WHERE storage_location_id = ?",
                [$loc['id']]
            )['total'] ?? 0);

            $diskUsage = ServerStats::getDiskUsage($loc['path']);
            $loc['disk_total'] = $diskUsage['total'] ?? 0;
            $loc['disk_used'] = $diskUsage['used'] ?? 0;
            $loc['disk_free'] = $diskUsage['free'] ?? 0;
            $loc['disk_percent'] = $diskUsage['percent'] ?? 0;
        }
        unset($loc);

        // Remote SSH configs
        $remoteSshService = new \BBS\Services\RemoteSshService();
        $remoteSshConfigs = $remoteSshService->getAll();
        $remoteRepoCount = (int) ($this->db->fetchOne("SELECT COUNT(*) as cnt FROM repositories WHERE storage_type = 'remote_ssh'")['cnt'] ?? 0);

        // Attach repo counts to each remote SSH config
        foreach ($remoteSshConfigs as &$rsc) {
            $rsc['repo_count'] = (int) ($this->db->fetchOne(
                "SELECT COUNT(*) as cnt FROM repositories WHERE remote_ssh_config_id = ?",
                [$rsc['id']]
            )['cnt'] ?? 0);
        }
        unset($rsc);

        // S3 settings
        $settingsRows = $this->db->fetchAll("SELECT `key`, `value` FROM settings WHERE `key` IN ('s3_endpoint', 's3_bucket', 's3_region', 's3_path_prefix', 's3_sync_server_backups')");
        $settings = [];
        foreach ($settingsRows as $row) {
            $settings[$row['key']] = $row['value'];
        }

        $this->view('storage-locations/index', [
            'pageTitle' => 'Storage Locations',
            'locations' => $locations,
            'remoteSshConfigs' => $remoteSshConfigs,
            'remoteRepoCount' => $remoteRepoCount,
            'settings' => $settings,
        ]);
    }

    public function store(): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $label = trim($_POST['label'] ?? '');
        $path = rtrim(trim($_POST['path'] ?? ''), '/');
        $isDefault = !empty($_POST['is_default']) ? 1 : 0;

        if (empty($label) || empty($path)) {
            $this->flash('danger', 'Label and path are required.');
            $this->redirect('/storage-locations');
        }

        if (!preg_match('#^/#', $path)) {
            $this->flash('danger', 'Path must be an absolute path.');
            $this->redirect('/storage-locations');
        }

        // If marking as default, unset current default
        if ($isDefault) {
            $this->db->query("UPDATE storage_locations SET is_default = 0");
        }

        $this->db->insert('storage_locations', [
            'label' => $label,
            'path' => $path,
            'is_default' => $isDefault,
        ]);

        // Update the allowed-storage-paths config file for bbs-ssh-helper
        $this->updateAllowedPaths();

        $this->flash('success', "Storage location \"{$label}\" created.");
        $this->redirect('/storage-locations');
    }

    public function update(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $location = $this->db->fetchOne("SELECT * FROM storage_locations WHERE id = ?", [$id]);
        if (!$location) {
            $this->flash('danger', 'Storage location not found.');
            $this->redirect('/storage-locations');
        }

        $label = trim($_POST['label'] ?? '');
        $path = rtrim(trim($_POST['path'] ?? ''), '/');
        $isDefault = !empty($_POST['is_default']) ? 1 : 0;

        if (empty($label) || empty($path)) {
            $this->flash('danger', 'Label and path are required.');
            $this->redirect('/storage-locations');
        }

        // Don't allow changing path if repos exist (would break them)
        $repoCount = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM repositories WHERE storage_location_id = ?",
            [$id]
        )['cnt'] ?? 0);

        if ($repoCount > 0 && $path !== $location['path']) {
            $this->flash('danger', 'Cannot change path while repositories exist on this location.');
            $this->redirect('/storage-locations');
        }

        if ($isDefault) {
            $this->db->query("UPDATE storage_locations SET is_default = 0");
        }

        $this->db->update('storage_locations', [
            'label' => $label,
            'path' => $path,
            'is_default' => $isDefault,
        ], 'id = ?', [$id]);

        $this->updateAllowedPaths();

        $this->flash('success', "Storage location \"{$label}\" updated.");
        $this->redirect('/storage-locations');
    }

    public function destroy(int $id): void
    {
        $this->requireAdmin();
        $this->verifyCsrf();

        $location = $this->db->fetchOne("SELECT * FROM storage_locations WHERE id = ?", [$id]);
        if (!$location) {
            $this->flash('danger', 'Storage location not found.');
            $this->redirect('/storage-locations');
        }

        if ($location['is_default']) {
            $this->flash('danger', 'Cannot delete the default storage location.');
            $this->redirect('/storage-locations');
        }

        $repoCount = (int) ($this->db->fetchOne(
            "SELECT COUNT(*) as cnt FROM repositories WHERE storage_location_id = ?",
            [$id]
        )['cnt'] ?? 0);

        if ($repoCount > 0) {
            $this->flash('danger', 'Cannot delete a storage location that has repositories. Delete or move the repositories first.');
            $this->redirect('/storage-locations');
        }

        $this->db->delete('storage_locations', 'id = ?', [$id]);
        $this->updateAllowedPaths();

        $this->flash('success', "Storage location \"{$location['label']}\" deleted.");
        $this->redirect('/storage-locations');
    }

    /**
     * Write all storage location paths to /etc/bbs/allowed-storage-paths
     * so bbs-ssh-helper can validate repo directory creation on those paths.
     */
    private function updateAllowedPaths(): void
    {
        $locations = $this->db->fetchAll("SELECT path FROM storage_locations");
        $paths = array_column($locations, 'path');

        $cmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'update-allowed-paths'];
        foreach ($paths as $p) {
            $cmd[] = $p;
        }
        exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1', $output, $ret);
    }
}

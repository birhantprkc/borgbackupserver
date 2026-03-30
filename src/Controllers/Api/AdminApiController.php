<?php

namespace BBS\Controllers\Api;

use BBS\Core\Controller;
use BBS\Services\BorgCommandBuilder;
use BBS\Services\Encryption;
use BBS\Services\SshKeyManager;

class AdminApiController extends Controller
{
    private function getJsonInput(): array
    {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    // ── Clients ──────────────────────────────────────────

    public function listClients(): void
    {
        $this->requireApiToken();

        $agents = $this->db->fetchAll("
            SELECT a.id, a.name, a.hostname, a.ip_address, a.os_info,
                   a.borg_version, a.agent_version, a.status, a.last_heartbeat,
                   a.created_at, u.username as owner
            FROM agents a
            LEFT JOIN users u ON u.id = a.user_id
            ORDER BY a.name
        ");

        $this->json(['clients' => $agents]);
    }

    public function getClient(int $id): void
    {
        $this->requireApiToken();

        $agent = $this->db->fetchOne("
            SELECT a.id, a.name, a.hostname, a.ip_address, a.os_info,
                   a.borg_version, a.agent_version, a.status, a.last_heartbeat,
                   a.api_key, a.created_at, u.username as owner
            FROM agents a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE a.id = ?
        ", [$id]);

        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        // Include repos and plans
        $repos = $this->db->fetchAll(
            "SELECT id, name, path, encryption, storage_type, size_bytes, archive_count, created_at
             FROM repositories WHERE agent_id = ? ORDER BY name", [$id]
        );
        $plans = $this->db->fetchAll(
            "SELECT bp.id, bp.name, bp.directories, bp.excludes, bp.advanced_options, bp.enabled,
                    s.frequency, s.times, s.day_of_week, s.day_of_month
             FROM backup_plans bp
             LEFT JOIN schedules s ON s.backup_plan_id = bp.id
             WHERE bp.agent_id = ? ORDER BY bp.name", [$id]
        );

        $agent['repositories'] = $repos;
        $agent['plans'] = $plans;

        $this->json($agent);
    }

    public function createClient(): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();

        $name = trim($input['name'] ?? '');
        if (empty($name)) {
            $this->json(['error' => 'Client name is required'], 400);
        }

        $apiKey = bin2hex(random_bytes(32));

        $id = $this->db->insert('agents', [
            'name' => $name,
            'api_key' => $apiKey,
            'status' => 'setup',
            'user_id' => null,
        ]);

        // Determine SSH home path
        $storageSetting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
        $storagePath = $storageSetting['value'] ?? null;
        if (!$storagePath) {
            $this->db->delete('agents', 'id = ?', [$id]);
            $this->json(['error' => 'No storage path configured on server'], 500);
        }

        $sshHomePath = $storagePath;
        $matchingLocation = $this->db->fetchOne(
            "SELECT id FROM storage_locations WHERE path = ?",
            [rtrim($storagePath, '/')]
        );
        if ($matchingLocation && is_dir('/var/bbs/home')) {
            $sshHomePath = '/var/bbs/home';
        }

        $clientDir = rtrim($sshHomePath, '/') . '/' . $id;
        if (!is_dir($clientDir) && !@mkdir($clientDir, 0755, true)) {
            $this->db->delete('agents', 'id = ?', [$id]);
            $this->json(['error' => 'Failed to create storage directory'], 500);
        }

        $sshResult = SshKeyManager::provisionClient($id, $name, $sshHomePath);
        if (!$sshResult) {
            @rmdir($clientDir);
            $this->db->delete('agents', 'id = ?', [$id]);
            $this->json(['error' => 'SSH provisioning failed. Ensure bbs-ssh-helper is installed.'], 500);
        }

        $this->db->insert('server_log', [
            'agent_id' => $id,
            'level' => 'info',
            'message' => "Client created via API. SSH provisioned: user {$sshResult['unix_user']}, home {$sshResult['home_dir']}",
        ]);

        // Build install command
        $serverHost = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");
        $host = $serverHost['value'] ?? '';

        $this->json([
            'id' => (int) $id,
            'name' => $name,
            'api_key' => $apiKey,
            'status' => 'setup',
            'install_command' => $host ? "curl -s https://{$host}/get-agent | sudo bash -s -- --server https://{$host} --key {$apiKey}" : null,
        ], 201);
    }

    public function deleteClient(int $id): void
    {
        $this->requireApiToken();

        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$id]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        // Deprovision SSH user
        if (!empty($agent['ssh_unix_user'])) {
            SshKeyManager::deprovisionClient($agent['ssh_unix_user']);
        }

        // Remove storage directory
        $clientDir = $agent['ssh_home_dir'] ?? null;
        if ($clientDir && is_dir($clientDir)) {
            SshKeyManager::deleteStorage($clientDir);
        }

        // Drop ClickHouse catalog data
        try {
            $ch = \BBS\Core\ClickHouse::getInstance();
            $ch->exec("ALTER TABLE file_catalog DROP PARTITION " . (int) $id);
            $ch->exec("ALTER TABLE catalog_dirs DROP PARTITION " . (int) $id);
        } catch (\Exception $e) { /* ignore */ }

        $this->db->delete('agents', 'id = ?', [$id]);

        $this->json(['status' => 'ok', 'message' => "Client \"{$agent['name']}\" deleted"]);
    }

    // ── Repositories ─────────────────────────────────────

    public function listRepositories(int $agentId): void
    {
        $this->requireApiToken();

        $agent = $this->db->fetchOne("SELECT id FROM agents WHERE id = ?", [$agentId]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $repos = $this->db->fetchAll(
            "SELECT id, name, path, encryption, storage_type, size_bytes, archive_count, created_at
             FROM repositories WHERE agent_id = ? ORDER BY name", [$agentId]
        );

        $this->json(['repositories' => $repos]);
    }

    public function createRepository(int $agentId): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();

        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$agentId]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $name = trim($input['name'] ?? '');
        $encryption = $input['encryption'] ?? 'repokey-blake2';
        $passphrase = $input['passphrase'] ?? '';
        $storageType = $input['storage_type'] ?? 'local';
        $remoteSshConfigId = !empty($input['remote_ssh_config_id']) ? (int) $input['remote_ssh_config_id'] : null;

        if (empty($name)) {
            $this->json(['error' => 'Repository name is required'], 400);
        }

        // Route to remote SSH handler if requested
        if ($storageType === 'remote_ssh') {
            $this->createRemoteSshRepository($agentId, $name, $encryption, $passphrase, $remoteSshConfigId);
            return;
        }

        $validEncryptions = ['none', 'repokey', 'repokey-blake2', 'authenticated', 'authenticated-blake2'];
        if (!in_array($encryption, $validEncryptions)) {
            $this->json(['error' => 'Invalid encryption type. Valid: ' . implode(', ', $validEncryptions)], 400);
        }

        // Auto-generate passphrase if needed
        if (empty($passphrase) && $encryption !== 'none') {
            $segments = [];
            for ($i = 0; $i < 5; $i++) {
                $segments[] = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
            }
            $passphrase = implode('-', $segments);
        }

        // Sanitize name for filesystem
        $safeName = $this->sanitizeRepoName($name);
        if (empty($safeName)) {
            $this->json(['error' => 'Repository name must contain at least one alphanumeric character'], 400);
        }

        // Resolve storage location
        $storageLocationId = !empty($input['storage_location_id']) ? (int) $input['storage_location_id'] : null;
        $location = null;
        if ($storageLocationId) {
            $location = $this->db->fetchOne("SELECT * FROM storage_locations WHERE id = ?", [$storageLocationId]);
        }
        if (!$location) {
            $location = $this->db->fetchOne("SELECT * FROM storage_locations WHERE is_default = 1");
        }
        if (!$location) {
            $storageSetting = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'storage_path'");
            $location = ['id' => null, 'path' => $storageSetting['value'] ?? '/var/bbs', 'is_default' => 1];
        }

        $serverHost = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");
        $host = !empty($agent['server_host_override']) ? $agent['server_host_override'] : ($serverHost['value'] ?? '');

        // Build path
        $locationPath = rtrim($location['path'], '/');
        $sshHomeDir = $agent['ssh_home_dir'] ?? null;
        $sshHomePath = $sshHomeDir ? rtrim(dirname($sshHomeDir), '/') : null;
        $isNonDefault = !$sshHomePath || $locationPath !== $sshHomePath;

        if ($isNonDefault) {
            $localPath = $locationPath . '/' . $agentId . '/' . $safeName;
            if (!empty($agent['ssh_unix_user']) && !empty($host)) {
                $sshHost = SshKeyManager::stripHostPort($host);
                $path = "ssh://{$agent['ssh_unix_user']}@{$sshHost}//{$localPath}";
            } else {
                $path = $localPath;
            }
        } else {
            if (!empty($agent['ssh_unix_user']) && !empty($host)) {
                $path = SshKeyManager::buildSshRepoPath($agent['ssh_unix_user'], $host, $safeName);
            } else {
                $path = rtrim($location['path'], '/') . '/' . $agentId . '/' . $safeName;
            }
        }

        // Check for duplicate path
        $existing = $this->db->fetchOne("SELECT id FROM repositories WHERE path = ?", [$path]);
        if ($existing) {
            $this->json(['error' => 'A repository already exists at that path. Try a different name.'], 409);
        }

        $repoId = $this->db->insert('repositories', [
            'agent_id' => $agentId,
            'storage_type' => 'local',
            'storage_location_id' => $location['id'] ?? null,
            'name' => $safeName,
            'path' => $path,
            'encryption' => $encryption,
            'passphrase_encrypted' => $encryption !== 'none' ? Encryption::encrypt($passphrase) : null,
        ]);

        // Run borg init
        $repo = $this->db->fetchOne("SELECT * FROM repositories WHERE id = ?", [$repoId]);
        $repoLocalPath = BorgCommandBuilder::getLocalRepoPath($repo);

        $helperCmd = ['sudo', '/usr/local/bin/bbs-ssh-helper', 'create-repo-dir', $repoLocalPath];
        exec(implode(' ', array_map('escapeshellarg', $helperCmd)) . ' 2>&1', $helperOutput, $helperRet);
        if ($helperRet !== 0) {
            $parentDir = dirname($repoLocalPath);
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0755, true);
            }
        }

        $initCmd = BorgCommandBuilder::buildInitCommand($repo);
        $env = BorgCommandBuilder::buildEnv($repo);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($initCmd, $descriptors, $pipes, null, $env);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        if ($exitCode !== 0) {
            $errorMsg = trim($stderr ?: $stdout);
            $this->db->insert('server_log', [
                'agent_id' => $agentId,
                'level' => 'error',
                'message' => "borg init failed for repo \"{$safeName}\" via API: {$errorMsg}",
            ]);
        }

        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'level' => 'info',
            'message' => "Repository \"{$safeName}\" created via API ({$encryption})",
        ]);

        $response = [
            'id' => (int) $repoId,
            'name' => $safeName,
            'path' => $path,
            'encryption' => $encryption,
            'storage_type' => 'local',
        ];

        if ($encryption !== 'none') {
            $response['passphrase'] = $passphrase;
        }

        if ($exitCode !== 0) {
            $response['warning'] = 'Repository created in database but borg init failed: ' . ($errorMsg ?? 'unknown error');
        }

        $this->json($response, 201);
    }

    // ── Backup Plans ─────────────────────────────────────

    public function listPlans(int $agentId): void
    {
        $this->requireApiToken();

        $agent = $this->db->fetchOne("SELECT id FROM agents WHERE id = ?", [$agentId]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $plans = $this->db->fetchAll("
            SELECT bp.id, bp.name, bp.directories, bp.excludes, bp.advanced_options,
                   bp.enabled, bp.repository_id, r.name as repository_name,
                   s.frequency, s.times, s.day_of_week, s.day_of_month
            FROM backup_plans bp
            LEFT JOIN schedules s ON s.backup_plan_id = bp.id
            LEFT JOIN repositories r ON r.id = bp.repository_id
            WHERE bp.agent_id = ?
            ORDER BY bp.name
        ", [$agentId]);

        $this->json(['plans' => $plans]);
    }

    public function createPlan(int $agentId): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();

        $agent = $this->db->fetchOne("SELECT id FROM agents WHERE id = ?", [$agentId]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $name = trim($input['name'] ?? '');
        $repositoryId = (int) ($input['repository_id'] ?? 0);
        $directories = trim($input['directories'] ?? '');
        $excludes = trim($input['excludes'] ?? '');
        $advancedOptions = trim($input['advanced_options'] ?? '--compression lz4 --exclude-caches --noatime');
        $frequency = $input['frequency'] ?? 'daily';
        $times = $input['times'] ?? '02:00';
        $dayOfWeek = $input['day_of_week'] ?? null;
        $dayOfMonth = $input['day_of_month'] ?? null;
        $pruneMinutes = (int) ($input['prune_minutes'] ?? 0);
        $pruneHours = (int) ($input['prune_hours'] ?? 0);
        $pruneDays = (int) ($input['prune_days'] ?? 7);
        $pruneWeeks = (int) ($input['prune_weeks'] ?? 4);
        $pruneMonths = (int) ($input['prune_months'] ?? 6);
        $pruneYears = (int) ($input['prune_years'] ?? 0);

        if (empty($name) || empty($directories) || empty($repositoryId)) {
            $this->json(['error' => 'name, repository_id, and directories are required'], 400);
        }

        // Verify repository belongs to this agent
        $repo = $this->db->fetchOne(
            "SELECT id FROM repositories WHERE id = ? AND agent_id = ?",
            [$repositoryId, $agentId]
        );
        if (!$repo) {
            $this->json(['error' => 'Repository not found or does not belong to this client'], 404);
        }

        $validFreqs = ['hourly', 'daily', 'weekly', 'monthly', 'manual'];
        if (!in_array($frequency, $validFreqs)) {
            $this->json(['error' => 'Invalid frequency. Valid: ' . implode(', ', $validFreqs)], 400);
        }

        $planId = $this->db->insert('backup_plans', [
            'agent_id' => $agentId,
            'repository_id' => $repositoryId,
            'name' => $name,
            'directories' => $directories,
            'excludes' => $excludes ?: null,
            'advanced_options' => $advancedOptions,
            'prune_minutes' => $pruneMinutes,
            'prune_hours' => $pruneHours,
            'prune_days' => $pruneDays,
            'prune_weeks' => $pruneWeeks,
            'prune_months' => $pruneMonths,
            'prune_years' => $pruneYears,
            'enabled' => 1,
        ]);

        // Create schedule
        $scheduleId = $this->db->insert('schedules', [
            'backup_plan_id' => $planId,
            'frequency' => $frequency,
            'times' => $times,
            'day_of_week' => $dayOfWeek,
            'day_of_month' => $dayOfMonth,
            'enabled' => $frequency !== 'manual' ? 1 : 0,
        ]);

        // Attach plugin configs if provided
        // Format: "plugins": [{"plugin_config_id": 5}, {"plugin_config_id": 8}]
        // Or: "plugins": {"1": 5, "2": 8}  (plugin_id: plugin_config_id)
        $plugins = $input['plugins'] ?? [];
        $order = 0;
        if (is_array($plugins)) {
            foreach ($plugins as $key => $val) {
                if (is_array($val)) {
                    // Array of objects: [{"plugin_config_id": 5}]
                    $configId = (int) ($val['plugin_config_id'] ?? 0);
                    if ($configId <= 0) continue;
                    $pc = $this->db->fetchOne("SELECT plugin_id FROM plugin_configs WHERE id = ? AND agent_id = ?", [$configId, $agentId]);
                    if (!$pc) continue;
                    $this->db->insert('backup_plan_plugins', [
                        'backup_plan_id' => $planId,
                        'plugin_id' => $pc['plugin_id'],
                        'plugin_config_id' => $configId,
                        'config' => '{}',
                        'execution_order' => $order++,
                        'enabled' => 1,
                    ]);
                } else {
                    // Map format: {plugin_id: config_id}
                    $pluginId = (int) $key;
                    $configId = (int) $val;
                    if ($pluginId <= 0 || $configId <= 0) continue;
                    $this->db->insert('backup_plan_plugins', [
                        'backup_plan_id' => $planId,
                        'plugin_id' => $pluginId,
                        'plugin_config_id' => $configId,
                        'config' => '{}',
                        'execution_order' => $order++,
                        'enabled' => 1,
                    ]);
                }
            }
        }

        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'level' => 'info',
            'message' => "Backup plan \"{$name}\" created via API (schedule: {$frequency})",
        ]);

        $this->json([
            'id' => (int) $planId,
            'name' => $name,
            'repository_id' => $repositoryId,
            'directories' => $directories,
            'frequency' => $frequency,
            'schedule_id' => (int) $scheduleId,
            'plugins_attached' => $order,
        ], 201);
    }

    // ── Plugins ──────────────────────────────────────────

    public function listPlugins(): void
    {
        $this->requireApiToken();

        $plugins = $this->db->fetchAll("SELECT id, slug, name, description, plugin_type, is_active FROM plugins ORDER BY name");

        $this->json(['plugins' => $plugins]);
    }

    public function listPluginConfigs(int $agentId): void
    {
        $this->requireApiToken();

        $agent = $this->db->fetchOne("SELECT id FROM agents WHERE id = ?", [$agentId]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $configs = $this->db->fetchAll("
            SELECT pc.id, pc.name, pc.config, p.slug, p.name as plugin_name
            FROM plugin_configs pc
            JOIN plugins p ON p.id = pc.plugin_id
            WHERE pc.agent_id = ?
            ORDER BY p.name, pc.name
        ", [$agentId]);

        // Decode config JSON and mask sensitive fields
        foreach ($configs as &$cfg) {
            $decoded = json_decode($cfg['config'], true) ?: [];
            // Mask sensitive values
            foreach ($decoded as $k => &$v) {
                if (in_array($k, ['password', 'secret_key', 'access_key']) && !empty($v)) {
                    $v = '********';
                }
            }
            $cfg['config'] = $decoded;
        }

        $this->json(['plugin_configs' => $configs]);
    }

    public function createPluginConfig(int $agentId): void
    {
        $this->requireApiToken();
        $input = $this->getJsonInput();

        $agent = $this->db->fetchOne("SELECT id FROM agents WHERE id = ?", [$agentId]);
        if (!$agent) {
            $this->json(['error' => 'Client not found'], 404);
        }

        $pluginSlug = trim($input['plugin'] ?? '');
        $configName = trim($input['name'] ?? '');
        $config = $input['config'] ?? [];

        if (empty($pluginSlug) || empty($configName)) {
            $this->json(['error' => 'plugin (slug) and name are required'], 400);
        }

        $plugin = $this->db->fetchOne("SELECT id, slug FROM plugins WHERE slug = ?", [$pluginSlug]);
        if (!$plugin) {
            $this->json(['error' => "Unknown plugin: {$pluginSlug}"], 404);
        }

        // Check duplicate name
        $existing = $this->db->fetchOne(
            "SELECT id FROM plugin_configs WHERE agent_id = ? AND plugin_id = ? AND name = ?",
            [$agentId, $plugin['id'], $configName]
        );
        if ($existing) {
            $this->json(['error' => "A config named \"{$configName}\" already exists for this plugin"], 409);
        }

        // Encrypt sensitive fields
        $schema = (new \BBS\Services\PluginManager($this->db))->getPluginSchema($pluginSlug);
        foreach ($schema as $field => $def) {
            if (!empty($def['sensitive']) && !empty($config[$field])) {
                $config[$field] = Encryption::encrypt($config[$field]);
            }
        }

        // Enable plugin for agent if not already
        $agentPlugin = $this->db->fetchOne(
            "SELECT id FROM agent_plugins WHERE agent_id = ? AND plugin_id = ?",
            [$agentId, $plugin['id']]
        );
        if (!$agentPlugin) {
            $this->db->insert('agent_plugins', [
                'agent_id' => $agentId,
                'plugin_id' => $plugin['id'],
                'enabled' => 1,
            ]);
        } else {
            $this->db->update('agent_plugins', ['enabled' => 1], 'id = ?', [$agentPlugin['id']]);
        }

        $configId = $this->db->insert('plugin_configs', [
            'agent_id' => $agentId,
            'plugin_id' => $plugin['id'],
            'name' => $configName,
            'config' => json_encode($config),
        ]);

        $this->json([
            'id' => (int) $configId,
            'plugin' => $pluginSlug,
            'name' => $configName,
        ], 201);
    }

    public function getPluginSchema(): void
    {
        $this->requireApiToken();

        $pm = new \BBS\Services\PluginManager($this->db);
        $plugins = $this->db->fetchAll("SELECT slug, name FROM plugins WHERE is_active = 1 ORDER BY name");

        $schemas = [];
        foreach ($plugins as $p) {
            $schema = $pm->getPluginSchema($p['slug']);
            // Strip sensitive defaults
            foreach ($schema as &$field) {
                if (!empty($field['sensitive'])) {
                    unset($field['default']);
                }
            }
            $schemas[$p['slug']] = [
                'name' => $p['name'],
                'fields' => $schema,
            ];
        }

        $this->json(['schemas' => $schemas]);
    }

    // ── Storage Locations ────────────────────────────────

    public function listStorageLocations(): void
    {
        $this->requireApiToken();

        $locations = $this->db->fetchAll("SELECT id, name, path, is_default, created_at FROM storage_locations ORDER BY name");
        $remoteConfigs = $this->db->fetchAll("
            SELECT id, name, provider, remote_host, remote_port, remote_user, remote_base_path,
                   borg_remote_path, append_repo_name, disk_total_bytes, disk_used_bytes,
                   disk_free_bytes, disk_checked_at, created_at
            FROM remote_ssh_configs ORDER BY name
        ");

        $this->json([
            'local' => $locations,
            'remote_ssh' => $remoteConfigs,
        ]);
    }

    // ── Remote SSH Repos ────────────────────────────────

    private function createRemoteSshRepository(int $agentId, string $name, string $encryption, string $passphrase, ?int $remoteSshConfigId): void
    {
        if (!$remoteSshConfigId) {
            $this->json(['error' => 'remote_ssh_config_id is required for remote SSH repositories'], 400);
        }

        $remoteSshService = new \BBS\Services\RemoteSshService();
        $config = $remoteSshService->getById($remoteSshConfigId);
        if (!$config) {
            $this->json(['error' => 'Remote SSH config not found'], 404);
        }

        $safeName = $this->sanitizeRepoName($name);

        // Auto-generate passphrase if needed
        if (empty($passphrase) && $encryption !== 'none') {
            $segments = [];
            for ($i = 0; $i < 5; $i++) {
                $segments[] = strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
            }
            $passphrase = implode('-', $segments);
        }

        $repoPath = $remoteSshService->buildRepoPath($config, $safeName);

        $result = $remoteSshService->initRepo($config, $repoPath, $encryption, $passphrase);
        if (!$result['success']) {
            $errorMsg = $result['stderr'] ?? $result['output'] ?? 'Unknown error';
            $this->db->insert('server_log', [
                'agent_id' => $agentId,
                'level' => 'error',
                'message' => "borg init failed for remote repo \"{$safeName}\" via API on {$config['remote_host']}: {$errorMsg}",
            ]);
            $this->json(['error' => "Failed to initialize repository on {$config['remote_host']}: {$errorMsg}"], 500);
        }

        $repoId = $this->db->insert('repositories', [
            'agent_id' => $agentId,
            'storage_type' => 'remote_ssh',
            'remote_ssh_config_id' => $remoteSshConfigId,
            'name' => $safeName,
            'path' => $repoPath,
            'encryption' => $encryption,
            'passphrase_encrypted' => $encryption !== 'none' ? Encryption::encrypt($passphrase) : null,
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'level' => 'info',
            'message' => "Remote repository \"{$safeName}\" created via API on {$config['remote_user']}@{$config['remote_host']}",
        ]);

        $response = [
            'id' => (int) $repoId,
            'name' => $safeName,
            'path' => $repoPath,
            'encryption' => $encryption,
            'storage_type' => 'remote_ssh',
            'remote_host' => $config['remote_host'],
        ];

        if ($encryption !== 'none') {
            $response['passphrase'] = $passphrase;
        }

        $this->json($response, 201);
    }

    // ── Helpers ──────────────────────────────────────────

    private function sanitizeRepoName(string $name): string
    {
        $slug = mb_strtolower($name, 'UTF-8');
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug);
        $slug = preg_replace('/-{2,}/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug ?: 'repo';
    }
}

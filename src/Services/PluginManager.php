<?php

namespace BBS\Services;

use BBS\Core\Database;

class PluginManager
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get all active plugins.
     */
    public function getAllPlugins(): array
    {
        return $this->db->fetchAll("SELECT * FROM plugins WHERE is_active = 1 ORDER BY name ASC");
    }

    /**
     * Get plugins with their enabled state for an agent.
     */
    public function getAgentPlugins(int $agentId): array
    {
        return $this->db->fetchAll("
            SELECT p.*, IFNULL(ap.enabled, 0) AS agent_enabled
            FROM plugins p
            LEFT JOIN agent_plugins ap ON ap.plugin_id = p.id AND ap.agent_id = ?
            WHERE p.is_active = 1
            ORDER BY p.name ASC
        ", [$agentId]);
    }

    /**
     * Get only enabled plugins for an agent.
     */
    public function getEnabledAgentPlugins(int $agentId): array
    {
        return $this->db->fetchAll("
            SELECT p.*
            FROM plugins p
            JOIN agent_plugins ap ON ap.plugin_id = p.id
            WHERE ap.agent_id = ? AND ap.enabled = 1 AND p.is_active = 1
            ORDER BY p.name ASC
        ", [$agentId]);
    }

    /**
     * Enable or disable a plugin for an agent.
     */
    public function setAgentPlugin(int $agentId, int $pluginId, bool $enabled): void
    {
        $existing = $this->db->fetchOne(
            "SELECT id FROM agent_plugins WHERE agent_id = ? AND plugin_id = ?",
            [$agentId, $pluginId]
        );

        if ($existing) {
            $this->db->update('agent_plugins', ['enabled' => $enabled ? 1 : 0], 'id = ?', [$existing['id']]);
        } else {
            $this->db->insert('agent_plugins', [
                'agent_id' => $agentId,
                'plugin_id' => $pluginId,
                'enabled' => $enabled ? 1 : 0,
            ]);
        }
    }

    /**
     * Get plugin config for a specific backup plan.
     */
    public function getPlanPlugins(int $planId): array
    {
        return $this->db->fetchAll("
            SELECT bpp.*, p.slug, p.name, p.description
            FROM backup_plan_plugins bpp
            JOIN plugins p ON p.id = bpp.plugin_id
            WHERE bpp.backup_plan_id = ? AND bpp.enabled = 1
            ORDER BY bpp.execution_order ASC
        ", [$planId]);
    }

    /**
     * Save plugin configurations for a backup plan.
     */
    public function savePlanPlugins(int $planId, array $enabledPlugins, array $pluginConfigs): void
    {
        // Fetch existing configs to preserve passwords if not changed
        $existing = $this->db->fetchAll(
            "SELECT plugin_id, config FROM backup_plan_plugins WHERE backup_plan_id = ?",
            [$planId]
        );
        $existingByPlugin = [];
        foreach ($existing as $row) {
            $existingByPlugin[$row['plugin_id']] = json_decode($row['config'], true) ?: [];
        }

        // Remove existing configs
        $this->db->query("DELETE FROM backup_plan_plugins WHERE backup_plan_id = ?", [$planId]);

        $order = 0;
        foreach ($enabledPlugins as $pluginId) {
            $config = $pluginConfigs[$pluginId] ?? [];

            // Encrypt password fields; preserve existing if empty
            if (!empty($config['password'])) {
                $config['password'] = Encryption::encrypt($config['password']);
            } elseif (isset($existingByPlugin[$pluginId]['password'])) {
                $config['password'] = $existingByPlugin[$pluginId]['password'];
            }

            // Convert comma-separated strings to arrays for tag fields
            $schema = $this->getPluginSchema($this->getPluginSlug($pluginId));
            foreach ($schema as $field => $def) {
                if (($def['type'] ?? '') === 'tags' && isset($config[$field]) && is_string($config[$field])) {
                    $config[$field] = array_map('trim', explode(',', $config[$field]));
                }
            }

            // Convert checkbox values
            foreach ($schema as $field => $def) {
                if (($def['type'] ?? '') === 'checkbox') {
                    $config[$field] = isset($config[$field]) && $config[$field] ? true : false;
                }
            }

            $this->db->insert('backup_plan_plugins', [
                'backup_plan_id' => $planId,
                'plugin_id' => (int) $pluginId,
                'config' => json_encode($config),
                'execution_order' => $order++,
                'enabled' => 1,
            ]);
        }
    }

    /**
     * Build plugin payload for agent task.
     * Decrypts passwords before sending.
     */
    public function buildPluginPayload(int $planId, int $agentId): array
    {
        $planPlugins = $this->getPlanPlugins($planId);
        if (empty($planPlugins)) {
            return [];
        }

        // Check which plugins are enabled for this agent
        $enabledSlugs = [];
        foreach ($this->getEnabledAgentPlugins($agentId) as $p) {
            $enabledSlugs[] = $p['slug'];
        }

        $payload = [];
        foreach ($planPlugins as $pp) {
            if (!in_array($pp['slug'], $enabledSlugs)) {
                continue;
            }

            // Resolve config from named plugin_config if available, else inline
            if (!empty($pp['plugin_config_id'])) {
                $resolved = $this->buildTestPayload($pp['plugin_config_id']);
                if (!empty($resolved)) {
                    $payload[] = $resolved;
                    continue;
                }
            }

            // Fallback to inline config
            $config = json_decode($pp['config'], true) ?: [];

            // Decrypt password
            if (!empty($config['password'])) {
                try {
                    $config['password'] = Encryption::decrypt($config['password']);
                } catch (\Exception $e) {
                    // May already be plaintext
                }
            }

            $payload[] = [
                'slug' => $pp['slug'],
                'config' => $config,
            ];
        }

        return $payload;
    }

    /**
     * Get all named plugin configs for an agent.
     */
    public function getPluginConfigs(int $agentId): array
    {
        return $this->db->fetchAll("
            SELECT pc.*, p.slug, p.name as plugin_name, p.description as plugin_description
            FROM plugin_configs pc
            JOIN plugins p ON p.id = pc.plugin_id
            WHERE pc.agent_id = ?
            ORDER BY p.name ASC, pc.name ASC
        ", [$agentId]);
    }

    /**
     * Get a single named plugin config by ID.
     */
    public function getPluginConfig(int $configId): ?array
    {
        return $this->db->fetchOne("
            SELECT pc.*, p.slug, p.name as plugin_name
            FROM plugin_configs pc
            JOIN plugins p ON p.id = pc.plugin_id
            WHERE pc.id = ?
        ", [$configId]);
    }

    /**
     * Save a new named plugin config.
     */
    public function savePluginConfig(int $agentId, int $pluginId, string $name, array $config): int
    {
        $slug = $this->getPluginSlug($pluginId);
        $config = $this->processConfigFields($slug, $config);

        return $this->db->insert('plugin_configs', [
            'agent_id' => $agentId,
            'plugin_id' => $pluginId,
            'name' => $name,
            'config' => json_encode($config),
        ]);
    }

    /**
     * Update an existing named plugin config.
     */
    public function updatePluginConfig(int $configId, string $name, array $config): void
    {
        $existing = $this->getPluginConfig($configId);
        if (!$existing) return;

        // Preserve encrypted password if new value is empty
        $existingConfig = json_decode($existing['config'], true) ?: [];
        if (empty($config['password']) && !empty($existingConfig['password'])) {
            $config['password'] = $existingConfig['password'];
        }

        $config = $this->processConfigFields($existing['slug'], $config, true);

        $this->db->update('plugin_configs', [
            'name' => $name,
            'config' => json_encode($config),
        ], 'id = ?', [$configId]);
    }

    /**
     * Delete a named plugin config.
     */
    public function deletePluginConfig(int $configId): void
    {
        $this->db->delete('plugin_configs', 'id = ?', [$configId]);
    }

    /**
     * Build a test payload for a plugin config (decrypts password).
     */
    public function buildTestPayload(int $configId): array
    {
        $pc = $this->getPluginConfig($configId);
        if (!$pc) return [];

        $config = json_decode($pc['config'], true) ?: [];
        if (!empty($config['password'])) {
            try {
                $config['password'] = Encryption::decrypt($config['password']);
            } catch (\Exception $e) {
                // May already be plaintext
            }
        }

        return ['slug' => $pc['slug'], 'config' => $config];
    }

    /**
     * Process config fields: encrypt passwords, convert tags/checkboxes.
     */
    private function processConfigFields(string $slug, array $config, bool $passwordPreserved = false): array
    {
        $schema = $this->getPluginSchema($slug);

        // Encrypt password if it's a new plaintext value
        if (!empty($config['password']) && !$passwordPreserved) {
            $config['password'] = Encryption::encrypt($config['password']);
        }

        // Convert tags
        foreach ($schema as $field => $def) {
            if (($def['type'] ?? '') === 'tags' && isset($config[$field]) && is_string($config[$field])) {
                $config[$field] = array_map('trim', explode(',', $config[$field]));
            }
        }

        // Convert checkboxes
        foreach ($schema as $field => $def) {
            if (($def['type'] ?? '') === 'checkbox') {
                $config[$field] = isset($config[$field]) && $config[$field] ? true : false;
            }
        }

        return $config;
    }

    /**
     * Get slug for a plugin ID.
     */
    private function getPluginSlug(int $pluginId): string
    {
        $plugin = $this->db->fetchOne("SELECT slug FROM plugins WHERE id = ?", [$pluginId]);
        return $plugin['slug'] ?? '';
    }

    /**
     * Get config schema for a plugin.
     */
    public function getPluginSchema(string $slug): array
    {
        $schemas = [
            'mysql_dump' => [
                'host' => [
                    'type' => 'text',
                    'label' => 'MySQL Host',
                    'default' => 'localhost',
                ],
                'port' => [
                    'type' => 'number',
                    'label' => 'Port',
                    'default' => 3306,
                ],
                'user' => [
                    'type' => 'text',
                    'label' => 'Username',
                    'required' => true,
                    'help' => 'A least-privilege user with SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER grants.',
                ],
                'password' => [
                    'type' => 'password',
                    'label' => 'Password',
                    'required' => true,
                ],
                'databases' => [
                    'type' => 'text',
                    'label' => 'Databases',
                    'default' => '*',
                    'help' => 'Use * for all databases, or a comma-separated list of specific names.',
                ],
                'dump_dir' => [
                    'type' => 'text',
                    'label' => 'Dump Directory',
                    'default' => '/home/bbs/mysql',
                    'required' => true,
                    'help' => 'Local directory where dumps are saved. Include this path in the backup directories.',
                ],
                'per_database' => [
                    'type' => 'checkbox',
                    'label' => 'One file per database',
                    'default' => true,
                ],
                'compress' => [
                    'type' => 'checkbox',
                    'label' => 'Compress dumps (gzip)',
                    'default' => true,
                ],
                'cleanup_after' => [
                    'type' => 'checkbox',
                    'label' => 'Delete dumps after backup completes',
                    'default' => true,
                ],
                'exclude_databases' => [
                    'type' => 'tags',
                    'label' => 'Exclude Databases',
                    'default' => ['information_schema', 'performance_schema', 'sys'],
                    'help' => 'Comma-separated list of databases to skip when using * above.',
                ],
                'extra_options' => [
                    'type' => 'text',
                    'label' => 'Extra mysqldump Options',
                    'default' => '--single-transaction --quick --routines --triggers --events',
                ],
            ],
        ];

        return $schemas[$slug] ?? [];
    }

    /**
     * Get the help/setup text for a plugin.
     */
    public function getPluginHelp(string $slug): string
    {
        $help = [
            'mysql_dump' => 'CREATE USER \'backup_user\'@\'localhost\' IDENTIFIED BY \'strong_password\';' . "\n"
                . 'GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER ON *.* TO \'backup_user\'@\'localhost\';' . "\n"
                . 'FLUSH PRIVILEGES;',
        ];

        return $help[$slug] ?? '';
    }
}

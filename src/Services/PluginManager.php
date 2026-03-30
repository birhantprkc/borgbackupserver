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

            // Convert comma-separated strings to arrays for tag fields
            $schema = $this->getPluginSchema($this->getPluginSlug($pluginId));

            // Encrypt sensitive fields; preserve existing if empty
            foreach ($schema as $field => $def) {
                if (!empty($def['sensitive'])) {
                    if (!empty($config[$field])) {
                        $config[$field] = Encryption::encrypt($config[$field]);
                    } elseif (isset($existingByPlugin[$pluginId][$field])) {
                        $config[$field] = $existingByPlugin[$pluginId][$field];
                    }
                }
            }
            // Legacy password handling
            if (!empty($config['password']) && empty($schema['password']['sensitive'])) {
                $config['password'] = Encryption::encrypt($config['password']);
            } elseif (empty($config['password']) && isset($existingByPlugin[$pluginId]['password'])) {
                $config['password'] = $existingByPlugin[$pluginId]['password'];
            }
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

        // Preserve encrypted sensitive fields if new value is empty
        $existingConfig = json_decode($existing['config'], true) ?: [];
        $schema = $this->getPluginSchema($existing['slug']);
        foreach ($schema as $field => $def) {
            if (!empty($def['sensitive']) && empty($config[$field]) && !empty($existingConfig[$field])) {
                $config[$field] = $existingConfig[$field];
            }
        }
        // Legacy password field
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
        $schema = $this->getPluginSchema($pc['slug']);
        foreach ($schema as $field => $def) {
            if (!empty($def['sensitive']) && !empty($config[$field])) {
                try {
                    $config[$field] = Encryption::decrypt($config[$field]);
                } catch (\Exception $e) {
                    // May already be plaintext
                }
            }
        }
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

        // Encrypt sensitive fields if they are new plaintext values
        foreach ($schema as $field => $def) {
            if (!empty($def['sensitive']) && !empty($config[$field]) && !$passwordPreserved) {
                $config[$field] = Encryption::encrypt($config[$field]);
            }
        }
        // Legacy: also handle 'password' field specifically
        if (!empty($config['password']) && !$passwordPreserved && empty($schema['password']['sensitive'])) {
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
                    'default' => 'bbs_backup',
                ],
                'password' => [
                    'type' => 'text',
                    'label' => 'Password',
                    'required' => true,
                    'generate' => true,
                    'sensitive' => true,
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
                    'help' => 'Local directory where dumps are saved. Automatically included in backup directories.',
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
            'pg_dump' => [
                'host' => [
                    'type' => 'text',
                    'label' => 'PostgreSQL Host',
                    'default' => 'localhost',
                ],
                'port' => [
                    'type' => 'number',
                    'label' => 'Port',
                    'default' => 5432,
                ],
                'user' => [
                    'type' => 'text',
                    'label' => 'Username',
                    'required' => true,
                    'default' => 'bbs_backup',
                ],
                'password' => [
                    'type' => 'text',
                    'label' => 'Password',
                    'required' => true,
                    'generate' => true,
                    'sensitive' => true,
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
                    'default' => '/home/bbs/pgdump',
                    'required' => true,
                    'help' => 'Local directory where dumps are saved. Automatically included in backup directories.',
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
                    'default' => ['template0', 'template1', 'postgres'],
                    'help' => 'Comma-separated list of databases to skip when using * above.',
                ],
                'extra_options' => [
                    'type' => 'text',
                    'label' => 'Extra pg_dump Options',
                    'default' => '--no-owner --no-privileges',
                ],
            ],
            'mongo_dump' => [
                'host' => [
                    'type' => 'text',
                    'label' => 'MongoDB Host',
                    'default' => '127.0.0.1',
                    'help' => 'Use 127.0.0.1 instead of localhost to avoid IPv6 connection issues.',
                ],
                'port' => [
                    'type' => 'number',
                    'label' => 'Port',
                    'default' => 27017,
                ],
                'user' => [
                    'type' => 'text',
                    'label' => 'Username',
                    'default' => '',
                    'help' => 'Leave empty if MongoDB does not require authentication.',
                ],
                'password' => [
                    'type' => 'text',
                    'label' => 'Password',
                    'generate' => true,
                    'sensitive' => true,
                    'help' => 'Leave empty if MongoDB does not require authentication.',
                ],
                'auth_db' => [
                    'type' => 'text',
                    'label' => 'Authentication Database',
                    'default' => 'admin',
                    'help' => 'The database used for authentication (usually "admin").',
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
                    'default' => '/home/bbs/mongodump',
                    'required' => true,
                    'help' => 'Local directory where dumps are saved. Automatically included in backup directories.',
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
                    'default' => ['admin', 'config', 'local'],
                    'help' => 'Comma-separated list of databases to skip when using * above.',
                ],
                'extra_options' => [
                    'type' => 'text',
                    'label' => 'Extra mongodump Options',
                    'default' => '',
                    'help' => 'Additional command-line flags for mongodump (e.g. --oplog for replica sets).',
                ],
            ],
            's3_sync' => [
                'credential_source' => [
                    'type' => 'select',
                    'label' => 'S3 Credentials',
                    'options' => ['global' => 'Use Global S3 Settings', 'custom' => 'Custom Credentials'],
                    'default' => 'global',
                ],
                'endpoint' => [
                    'type' => 'text',
                    'label' => 'S3 Endpoint URL',
                    'help' => 'The S3 API endpoint for your provider and region.',
                    'show_when' => ['credential_source' => 'custom'],
                ],
                'region' => [
                    'type' => 'text',
                    'label' => 'Region',
                    'default' => 'us-east-1',
                    'show_when' => ['credential_source' => 'custom'],
                ],
                'bucket' => [
                    'type' => 'text',
                    'label' => 'Bucket Name',
                    'required' => true,
                    'show_when' => ['credential_source' => 'custom'],
                ],
                'access_key' => [
                    'type' => 'text',
                    'label' => 'Access Key ID',
                    'required' => true,
                    'sensitive' => true,
                    'show_when' => ['credential_source' => 'custom'],
                ],
                'secret_key' => [
                    'type' => 'text',
                    'label' => 'Secret Access Key',
                    'required' => true,
                    'sensitive' => true,
                    'show_when' => ['credential_source' => 'custom'],
                ],
                'path_prefix' => [
                    'type' => 'text',
                    'label' => 'Path Prefix',
                    'default' => '',
                    'help' => 'Optional subfolder in bucket. Repo syncs to: bucket/prefix/agent-name/repo-name/',
                ],
                'bandwidth_limit' => [
                    'type' => 'text',
                    'label' => 'Bandwidth Limit',
                    'default' => '',
                    'help' => 'e.g. 50M for 50 MB/s. Leave empty for unlimited.',
                ],
            ],
            'shell_hook' => [
                'pre_script' => [
                    'type' => 'text',
                    'label' => 'Pre-Backup Script Path',
                    'help' => 'Absolute path to script on the client (e.g. /home/bbs/hooks/pre-backup.sh). Runs before borg starts. Leave empty to skip.',
                ],
                'post_script' => [
                    'type' => 'text',
                    'label' => 'Post-Backup Script Path',
                    'help' => 'Absolute path to script on the client (e.g. /home/bbs/hooks/post-backup.sh). Runs after borg completes. Leave empty to skip.',
                ],
                'abort_on_failure' => [
                    'type' => 'checkbox',
                    'label' => 'Abort backup if pre-script fails',
                    'default' => true,
                ],
                'timeout' => [
                    'type' => 'number',
                    'label' => 'Script Timeout (seconds)',
                    'default' => 300,
                    'help' => 'Maximum time each script is allowed to run before being killed.',
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
            'mysql_dump' => "-- Backup only (read-only):\n"
                . "CREATE USER 'backup_user'@'localhost' IDENTIFIED BY 'strong_password';\n"
                . "GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER ON *.* TO 'backup_user'@'localhost';\n"
                . "FLUSH PRIVILEGES;\n\n"
                . "-- For database restore via GUI (requires additional privileges):\n"
                . "GRANT SELECT, LOCK TABLES, SHOW VIEW, EVENT, TRIGGER,\n"
                . "       CREATE, INSERT, DROP, ALTER, INDEX, REFERENCES\n"
                . "       ON *.* TO 'backup_user'@'localhost';\n"
                . "FLUSH PRIVILEGES;",
            's3_sync' => "Requirements:\n"
                . "  apt install rclone\n\n"
                . "rclone must be installed on the BBS server.\n"
                . "This plugin syncs borg repositories to S3-compatible storage\n"
                . "(AWS S3, Backblaze B2, Wasabi, MinIO, etc.) after prune completes.\n\n"
                . "Configure global S3 credentials in Settings → Offsite Storage,\n"
                . "or use custom credentials per configuration.",
            'pg_dump' => "-- Backup only (read-only):\n"
                . "CREATE ROLE backup_user WITH LOGIN PASSWORD 'strong_password';\n"
                . "GRANT CONNECT ON DATABASE mydb TO backup_user;\n"
                . "GRANT USAGE ON SCHEMA public TO backup_user;\n"
                . "GRANT SELECT ON ALL TABLES IN SCHEMA public TO backup_user;\n"
                . "ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO backup_user;\n\n"
                . "-- For database restore via GUI (requires additional privileges):\n"
                . "ALTER ROLE backup_user CREATEDB;\n"
                . "GRANT ALL PRIVILEGES ON DATABASE mydb TO backup_user;",
            'mongo_dump' => "-- MongoDB user setup for backup (run in mongosh):\n"
                . "use admin\n"
                . "db.createUser({\n"
                . "  user: \"bbs_backup\",\n"
                . "  pwd: \"strong_password\",\n"
                . "  roles: [\n"
                . "    { role: \"backup\", db: \"admin\" },\n"
                . "    { role: \"readAnyDatabase\", db: \"admin\" }\n"
                . "  ]\n"
                . "})\n\n"
                . "-- For database restore via GUI (additional roles):\n"
                . "db.grantRolesToUser(\"bbs_backup\", [\n"
                . "  { role: \"restore\", db: \"admin\" },\n"
                . "  { role: \"readWriteAnyDatabase\", db: \"admin\" }\n"
                . "])\n\n"
                . "-- Note: Authentication is optional. Leave username/password\n"
                . "-- empty if your MongoDB instance does not require auth.",
        ];

        return $help[$slug] ?? '';
    }
}

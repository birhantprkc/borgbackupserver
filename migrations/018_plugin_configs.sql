-- Named plugin configurations (reusable across backup plans)
CREATE TABLE IF NOT EXISTS plugin_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    plugin_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    config JSON NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
    FOREIGN KEY (plugin_id) REFERENCES plugins(id) ON DELETE CASCADE,
    UNIQUE KEY unique_agent_config_name (agent_id, plugin_id, name)
) ENGINE=InnoDB;

-- Link backup_plan_plugins to named configs
ALTER TABLE backup_plan_plugins ADD COLUMN plugin_config_id INT NULL AFTER plugin_id;
ALTER TABLE backup_plan_plugins ADD FOREIGN KEY fk_bpp_plugin_config (plugin_config_id) REFERENCES plugin_configs(id) ON DELETE SET NULL;

-- Add plugin_test task type and plugin_config_id to jobs
ALTER TABLE backup_jobs MODIFY task_type ENUM('backup','prune','restore','check','compact','update_borg','update_agent','plugin_test') NOT NULL DEFAULT 'backup';
ALTER TABLE backup_jobs ADD COLUMN plugin_config_id INT NULL;

-- Migrate existing inline plugin configs into named configs
INSERT INTO plugin_configs (agent_id, plugin_id, name, config)
SELECT bp.agent_id, bpp.plugin_id, CONCAT(p.name, ' - ', bp.name), bpp.config
FROM backup_plan_plugins bpp
JOIN backup_plans bp ON bp.id = bpp.backup_plan_id
JOIN plugins p ON p.id = bpp.plugin_id
WHERE bpp.enabled = 1;

-- Point existing plan plugins at the new named configs
UPDATE backup_plan_plugins bpp
JOIN backup_plans bp ON bp.id = bpp.backup_plan_id
JOIN plugin_configs pc ON pc.agent_id = bp.agent_id AND pc.plugin_id = bpp.plugin_id AND JSON_CONTAINS(pc.config, bpp.config) AND JSON_CONTAINS(bpp.config, pc.config)
SET bpp.plugin_config_id = pc.id
WHERE bpp.enabled = 1;

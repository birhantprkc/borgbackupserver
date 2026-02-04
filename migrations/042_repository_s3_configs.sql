-- Migration: Decouple S3 sync from backup plans
-- S3 sync configuration is now stored per-repository, not per-plan

-- Create new table for repository-level S3 configuration
CREATE TABLE IF NOT EXISTS repository_s3_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    repository_id INT NOT NULL,
    plugin_config_id INT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_sync_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (repository_id) REFERENCES repositories(id) ON DELETE CASCADE,
    FOREIGN KEY (plugin_config_id) REFERENCES plugin_configs(id) ON DELETE RESTRICT,
    UNIQUE KEY unique_repo_s3 (repository_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migrate existing S3 sync configs from backup_plan_plugins to repository_s3_configs
-- Takes the first enabled s3_sync config found for each repository
INSERT INTO repository_s3_configs (repository_id, plugin_config_id, enabled, last_sync_at)
SELECT DISTINCT
    bp.repository_id,
    bpp.plugin_config_id,
    bpp.enabled,
    (SELECT MAX(bj.completed_at) FROM backup_jobs bj
     WHERE bj.repository_id = bp.repository_id
     AND bj.task_type = 's3_sync' AND bj.status = 'completed')
FROM backup_plan_plugins bpp
JOIN plugins p ON p.id = bpp.plugin_id
JOIN backup_plans bp ON bp.id = bpp.backup_plan_id
WHERE p.slug = 's3_sync'
AND bpp.plugin_config_id IS NOT NULL
AND bp.repository_id IS NOT NULL
ON DUPLICATE KEY UPDATE
    plugin_config_id = VALUES(plugin_config_id),
    enabled = VALUES(enabled);

-- Remove s3_sync entries from backup_plan_plugins (clean break)
DELETE bpp FROM backup_plan_plugins bpp
JOIN plugins p ON p.id = bpp.plugin_id
WHERE p.slug = 's3_sync';

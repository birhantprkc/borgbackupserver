-- Plugin system tables
CREATE TABLE IF NOT EXISTS plugins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    plugin_type ENUM('pre_backup', 'post_backup') NOT NULL DEFAULT 'pre_backup',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS agent_plugins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    plugin_id INT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
    FOREIGN KEY (plugin_id) REFERENCES plugins(id) ON DELETE CASCADE,
    UNIQUE KEY unique_agent_plugin (agent_id, plugin_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS backup_plan_plugins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    backup_plan_id INT NOT NULL,
    plugin_id INT NOT NULL,
    config JSON NOT NULL,
    execution_order INT NOT NULL DEFAULT 0,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (backup_plan_id) REFERENCES backup_plans(id) ON DELETE CASCADE,
    FOREIGN KEY (plugin_id) REFERENCES plugins(id) ON DELETE CASCADE,
    UNIQUE KEY unique_plan_plugin (backup_plan_id, plugin_id)
) ENGINE=InnoDB;

-- Seed MySQL plugin
INSERT INTO plugins (slug, name, description, plugin_type) VALUES
('mysql_dump', 'MySQL Database Dump', 'Dumps MySQL/MariaDB databases to a local directory before backup. Supports per-database dumps with optional cleanup after backup completion.', 'pre_backup');

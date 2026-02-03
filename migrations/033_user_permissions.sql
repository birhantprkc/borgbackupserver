-- Migration: Multi-user permission system
-- Creates user_agents junction table and user_permissions table

-- 1. Add all_clients flag to users
ALTER TABLE users ADD COLUMN all_clients TINYINT(1) NOT NULL DEFAULT 0 AFTER role;

-- 2. Create user_agents junction table
CREATE TABLE user_agents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    agent_id INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_agent (user_id, agent_id),
    INDEX idx_agent_id (agent_id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB;

-- 3. Create user_permissions table
CREATE TABLE user_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    permission ENUM('trigger_backup', 'manage_repos', 'manage_plans', 'restore', 'repo_maintenance') NOT NULL,
    agent_id INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_perm_agent (user_id, permission, agent_id),
    INDEX idx_user_id (user_id),
    INDEX idx_agent_id (agent_id)
) ENGINE=InnoDB;

-- 4. Migrate existing user_id assignments to user_agents
INSERT INTO user_agents (user_id, agent_id)
SELECT user_id, id FROM agents WHERE user_id IS NOT NULL
ON DUPLICATE KEY UPDATE created_at = created_at;

-- 5. Grant all permissions to migrated users for their current clients (global permissions)
INSERT INTO user_permissions (user_id, permission, agent_id)
SELECT DISTINCT a.user_id, p.permission, NULL
FROM agents a
CROSS JOIN (
    SELECT 'trigger_backup' AS permission UNION ALL
    SELECT 'manage_repos' UNION ALL
    SELECT 'manage_plans' UNION ALL
    SELECT 'restore' UNION ALL
    SELECT 'repo_maintenance'
) p
WHERE a.user_id IS NOT NULL
ON DUPLICATE KEY UPDATE created_at = created_at;

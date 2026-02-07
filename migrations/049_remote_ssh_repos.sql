-- Remote SSH repository support (rsync.net, BorgBase, Hetzner, etc.)

-- Reusable SSH connection configs (one rsync.net account holds many repos)
CREATE TABLE IF NOT EXISTS remote_ssh_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    remote_host VARCHAR(255) NOT NULL,
    remote_port INT NOT NULL DEFAULT 22,
    remote_user VARCHAR(100) NOT NULL,
    remote_base_path VARCHAR(500) NOT NULL DEFAULT './',
    ssh_private_key_encrypted TEXT NOT NULL,
    borg_remote_path VARCHAR(255) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Add storage type and remote config reference to repositories
ALTER TABLE repositories
    ADD COLUMN storage_type VARCHAR(20) NOT NULL DEFAULT 'local' AFTER agent_id,
    ADD COLUMN remote_ssh_config_id INT DEFAULT NULL AFTER storage_type;

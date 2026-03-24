ALTER TABLE agents ADD COLUMN server_host_override VARCHAR(255) DEFAULT NULL AFTER ssh_home_dir;
ALTER TABLE agents ADD COLUMN ssh_port_override INT DEFAULT NULL AFTER server_host_override;

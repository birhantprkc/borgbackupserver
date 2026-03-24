ALTER TABLE remote_ssh_configs ADD COLUMN disk_total_bytes BIGINT DEFAULT NULL AFTER append_repo_name;
ALTER TABLE remote_ssh_configs ADD COLUMN disk_used_bytes BIGINT DEFAULT NULL AFTER disk_total_bytes;
ALTER TABLE remote_ssh_configs ADD COLUMN disk_free_bytes BIGINT DEFAULT NULL AFTER disk_used_bytes;
ALTER TABLE remote_ssh_configs ADD COLUMN disk_checked_at DATETIME DEFAULT NULL AFTER disk_free_bytes;

ALTER TABLE remote_ssh_configs
    ADD COLUMN borgbase_api_key_encrypted TEXT DEFAULT NULL AFTER disk_checked_at,
    ADD COLUMN borgbase_repo_name VARCHAR(255) DEFAULT NULL AFTER borgbase_api_key_encrypted,
    ADD COLUMN borgbase_manual_quota_gb DECIMAL(12,3) DEFAULT NULL AFTER borgbase_repo_name,
    ADD COLUMN borgbase_usage_source VARCHAR(20) DEFAULT NULL AFTER borgbase_manual_quota_gb;

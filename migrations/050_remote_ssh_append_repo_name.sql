ALTER TABLE remote_ssh_configs
    ADD COLUMN append_repo_name TINYINT(1) NOT NULL DEFAULT 1 AFTER borg_remote_path;

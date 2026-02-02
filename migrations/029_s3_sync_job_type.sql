-- Add s3_sync as a backup_jobs task_type
ALTER TABLE backup_jobs MODIFY COLUMN task_type
    ENUM('backup', 'prune', 'restore', 'restore_mysql', 'check', 'compact', 'update_borg', 'update_agent', 'plugin_test', 's3_sync')
    NOT NULL DEFAULT 'backup';

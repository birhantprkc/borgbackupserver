-- Add repo maintenance task types to backup_jobs
ALTER TABLE backup_jobs
MODIFY COLUMN task_type ENUM('backup','prune','restore','restore_mysql','check','compact','update_borg','update_agent','plugin_test','s3_sync','repo_check','repo_repair','break_lock') NOT NULL DEFAULT 'backup';

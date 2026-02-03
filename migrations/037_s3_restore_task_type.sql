-- Add s3_restore task type for restoring repositories from S3
ALTER TABLE backup_jobs
MODIFY COLUMN task_type ENUM('backup','prune','restore','restore_mysql','restore_pg','check','compact','update_borg','update_agent','plugin_test','s3_sync','repo_check','repo_repair','break_lock','s3_restore') NOT NULL DEFAULT 'backup';

-- Add catalog_rebuild task type for rebuilding file catalog after S3 restore
ALTER TABLE backup_jobs
MODIFY COLUMN task_type ENUM('backup','prune','restore','restore_mysql','restore_pg','check','compact','update_borg','update_agent','plugin_test','s3_sync','repo_check','repo_repair','break_lock','s3_restore','catalog_sync','catalog_rebuild') NOT NULL DEFAULT 'backup';

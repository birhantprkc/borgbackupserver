ALTER TABLE backup_jobs MODIFY COLUMN task_type ENUM('backup','prune','restore','check','compact','update_borg') NOT NULL DEFAULT 'backup';

ALTER TABLE backup_jobs MODIFY task_type ENUM('backup','prune','restore','check','compact','update_borg','update_agent') NOT NULL DEFAULT 'backup';

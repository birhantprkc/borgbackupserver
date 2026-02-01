-- Add database restore support
ALTER TABLE archives ADD COLUMN databases_backed_up JSON DEFAULT NULL;
ALTER TABLE backup_jobs MODIFY COLUMN task_type ENUM('backup', 'prune', 'restore', 'restore_mysql', 'check', 'compact', 'update_borg', 'update_agent', 'plugin_test') NOT NULL DEFAULT 'backup';
ALTER TABLE backup_jobs ADD COLUMN restore_databases JSON DEFAULT NULL;

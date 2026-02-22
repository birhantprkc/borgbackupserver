ALTER TABLE backup_jobs
  MODIFY COLUMN task_type ENUM('backup', 'prune', 'restore', 'restore_mysql', 'restore_pg', 'check', 'compact', 'update_borg', 'update_agent', 'plugin_test', 's3_sync', 'repo_check', 'repo_repair', 'break_lock', 's3_restore', 'catalog_sync', 'catalog_rebuild', 'catalog_rebuild_full') NOT NULL DEFAULT 'backup';

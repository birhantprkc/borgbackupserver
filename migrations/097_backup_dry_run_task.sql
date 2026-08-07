-- Dry-run task type (#257): runs the plan's exact borg create command with
-- --dry-run so users can test exclude patterns; writes nothing to the repo.
ALTER TABLE backup_jobs MODIFY COLUMN task_type ENUM('backup', 'backup_dry_run', 'prune', 'restore', 'restore_mysql', 'restore_pg', 'restore_mongo', 'check', 'compact', 'update_borg', 'update_agent', 'plugin_test', 's3_sync', 'repo_check', 'repo_repair', 'break_lock', 's3_restore', 'catalog_sync', 'catalog_rebuild', 'catalog_rebuild_full', 'archive_delete', 'list_dir', 'archive_lock') NOT NULL DEFAULT 'backup';

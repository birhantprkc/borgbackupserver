-- Server-side archive_lock task (#314): lock/unlock renames queue like
-- archive deletes when the repository is busy, so the user never has to
-- wait for a running backup to finish.
ALTER TABLE backup_jobs MODIFY COLUMN task_type ENUM('backup', 'prune', 'restore', 'restore_mysql', 'restore_pg', 'restore_mongo', 'check', 'compact', 'update_borg', 'update_agent', 'plugin_test', 's3_sync', 'repo_check', 'repo_repair', 'break_lock', 's3_restore', 'catalog_sync', 'catalog_rebuild', 'catalog_rebuild_full', 'archive_delete', 'list_dir', 'archive_lock') NOT NULL DEFAULT 'backup';

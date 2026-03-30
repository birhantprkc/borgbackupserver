-- Add MongoDB dump plugin
INSERT IGNORE INTO plugins (slug, name, description, plugin_type) VALUES
('mongo_dump', 'MongoDB Backup/Restore', 'Dumps MongoDB databases using mongodump before backup. Supports per-database dumps with optional gzip compression and automatic cleanup. Restore via mongorestore.', 'pre_backup');

-- Add restore_mongo task type
ALTER TABLE backup_jobs MODIFY COLUMN task_type ENUM('backup', 'prune', 'restore', 'restore_mysql', 'restore_pg', 'restore_mongo', 'check', 'compact', 'update_borg', 'update_agent', 'plugin_test', 's3_sync', 'repo_check', 'repo_repair', 'break_lock', 's3_restore', 'catalog_sync', 'catalog_rebuild', 'catalog_rebuild_full') NOT NULL DEFAULT 'backup';

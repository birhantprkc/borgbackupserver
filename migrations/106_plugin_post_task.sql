-- Migration 106: deferred shell-hook post-scripts (#402)
--
-- The shell_hook post-script runs on the client the moment borg finishes, but
-- prune and offsite sync run afterwards on the BBS server. A script that powers
-- down the machine holding the repository therefore cuts the prune off part-way
-- through, or stops it happening at all.
--
-- The new task type carries the post-script back to the client once nothing is
-- left running for that repository.

ALTER TABLE backup_jobs MODIFY COLUMN task_type ENUM(
    'backup', 'backup_dry_run', 'prune', 'restore', 'restore_mysql', 'restore_pg', 'restore_mongo',
    'check', 'compact', 'update_borg', 'update_agent', 'plugin_test', 'plugin_post', 's3_sync',
    'repo_check', 'repo_repair', 'break_lock', 's3_restore', 'catalog_sync', 'catalog_rebuild',
    'catalog_rebuild_full', 'archive_delete', 'list_dir', 'archive_lock'
) NOT NULL DEFAULT 'backup';

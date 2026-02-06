-- Fix success/info event notifications that were created with default 'warning' severity
UPDATE notifications SET severity = 'info'
WHERE type IN ('backup_completed', 'restore_completed', 'agent_online', 'repo_compact_done', 's3_sync_done')
  AND severity = 'warning';

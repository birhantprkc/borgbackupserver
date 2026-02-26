ALTER TABLE backup_jobs ADD COLUMN last_progress_at DATETIME DEFAULT NULL AFTER completed_at;

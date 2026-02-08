ALTER TABLE backup_jobs ADD COLUMN status_message VARCHAR(255) DEFAULT NULL AFTER error_log;

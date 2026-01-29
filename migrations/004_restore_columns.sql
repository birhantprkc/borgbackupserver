-- Add restore-related columns to backup_jobs
ALTER TABLE backup_jobs
    ADD COLUMN restore_archive_id INT DEFAULT NULL AFTER error_log,
    ADD COLUMN restore_paths JSON DEFAULT NULL AFTER restore_archive_id,
    ADD COLUMN restore_destination VARCHAR(512) DEFAULT NULL AFTER restore_paths;

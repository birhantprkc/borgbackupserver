-- Add source_repository_id column for S3 restore "copy" mode
-- This allows restoring from one repo's S3 backup into a different target repo
ALTER TABLE backup_jobs ADD COLUMN source_repository_id INT NULL AFTER repository_id;

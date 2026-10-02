-- A repository restored as a copy from an offsite sync is read-only (#523).
-- It is a clone of the original: same borg ID and same encryption key.
-- copy_detached_at records when it was given its own borg ID; until then the
-- scheduler does that, which also fixes copies made before this change.
ALTER TABLE repositories
    ADD COLUMN read_only TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN copy_detached_at DATETIME DEFAULT NULL;

-- Copies made before this change: targets of a completed copy-mode restore.
UPDATE repositories r
JOIN backup_jobs bj ON bj.repository_id = r.id
   AND bj.task_type = 's3_restore'
   AND bj.source_repository_id IS NOT NULL
   AND bj.status = 'completed'
SET r.read_only = 1;

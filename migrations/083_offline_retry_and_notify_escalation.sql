-- Auto-retry on offline-induced backup failure (#249)
ALTER TABLE backup_jobs
    ADD COLUMN retry_count INT NOT NULL DEFAULT 0 AFTER had_warnings,
    ADD COLUMN parent_job_id INT DEFAULT NULL AFTER retry_count;

-- Escalation re-email when an unresolved notification keeps accumulating
-- occurrences. Without this, dedup silently swallows every failure after
-- the first — users think no email was sent when really it was sent once
-- weeks ago and every subsequent failure was deduplicated (#249).
ALTER TABLE notifications
    ADD COLUMN last_emailed_at DATETIME DEFAULT NULL AFTER occurrence_count;

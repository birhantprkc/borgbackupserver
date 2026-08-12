-- Migration 105: grace period + backoff for offline-induced backup retries (#404)
--
-- A running backup was failed and re-queued the moment its agent missed
-- 3 polls (90s by default). That threshold is shorter than the agent's own
-- 60s HTTP timeout, so one stalled request could trip it while borg was
-- still working — and the re-queued job dispatched immediately, re-running
-- a multi-hour backup from scratch on a host that was already overloaded.
--
-- not_before holds a queued job back until a given time, so retries can back
-- off instead of stacking up.

ALTER TABLE backup_jobs
    ADD COLUMN not_before DATETIME DEFAULT NULL AFTER retry_count;

INSERT INTO settings (`key`, `value`) VALUES
    ('job_offline_grace_minutes', '5'),
    ('auto_retry_backoff_minutes', '5')
ON DUPLICATE KEY UPDATE `key` = `key`;

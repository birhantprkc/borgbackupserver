-- Migration 110: per-profile "how stale is too stale" (#409)
--
-- Health reported a warning whenever any backup had failed in the last 24
-- hours. For a laptop that spends the weekend switched off that is not a
-- fault, it is a laptop — so anyone monitoring BBS from Uptime Kuma got a
-- steady stream of false alarms, and retry attempts could not stand in for a
-- threshold: capped at ten they only cover about eight hours, and raising the
-- cap would just pile retries on top of the next day's scheduled runs.
--
-- A profile now says how long a client of that kind may go without a
-- successful backup before anyone should care. Fourteen days for laptops, a
-- day for a database server. Null follows the server-wide setting.

ALTER TABLE client_profiles
    ADD COLUMN backup_overdue_hours INT DEFAULT NULL AFTER auto_retry_backoff_minutes;

INSERT INTO settings (`key`, `value`) VALUES ('backup_overdue_hours', '48')
ON DUPLICATE KEY UPDATE `key` = `key`;

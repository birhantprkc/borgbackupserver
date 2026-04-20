-- Per-user storage-alert thresholds (#156).
-- Each user picks their own trigger: percent-used, free-space-gb, or disabled.
-- The initial value for existing users comes from the server-wide threshold
-- so nobody loses their current alert behavior on upgrade.

ALTER TABLE users
    ADD COLUMN storage_alert_mode  ENUM('percent','gb_free','disabled') NOT NULL DEFAULT 'percent',
    ADD COLUMN storage_alert_value INT NOT NULL DEFAULT 90;

-- Backfill from the old server-wide threshold setting if it exists.
UPDATE users
JOIN (SELECT `value` FROM settings WHERE `key` = 'storage_alert_threshold' LIMIT 1) s
SET users.storage_alert_value = CAST(s.`value` AS SIGNED)
WHERE CAST(s.`value` AS SIGNED) BETWEEN 1 AND 100;

-- Notifications can be scoped to a specific user (storage_low is the first
-- user-scoped event). NULL = global notification, same as before for every
-- other event type.
ALTER TABLE notifications
    ADD COLUMN user_id INT DEFAULT NULL AFTER reference_id,
    ADD INDEX idx_user_unresolved (user_id, resolved_at, read_at),
    ADD CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

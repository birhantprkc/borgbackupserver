-- Push notification delivery support.
--
-- Device registrations already existed but nothing consumed them. This adds
-- what delivery needs: per-device preferences, an outbound queue so a slow or
-- unreachable relay can never block a request or the scheduler tick, and a
-- throttle stamp so a flapping alert doesn't push on every occurrence.

-- 1. Device rows carry a name, per-event preferences and an on/off switch.
--    `push_token` replaces `apns_token`: the column has always carried
--    registrations for more than one transport and the old name was
--    misleading. Widened because token length is not fixed across transports.
ALTER TABLE push_tokens
  CHANGE COLUMN apns_token push_token VARCHAR(512) NOT NULL,
  ADD COLUMN device_name VARCHAR(100) DEFAULT NULL,
  ADD COLUMN events JSON NOT NULL,
  ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 1;

-- `events` is NOT NULL on purpose. Preferences are materialised when a device
-- registers, so nothing ever has to interpret "absent" at query time — a NULL
-- here would match no event filter and silently mute the device.

-- 2. A device belongs to one user at a time. The app holds a single session,
--    so signing in as someone else on the same handset must move the device
--    rather than leave a second row delivering to the previous account.
--
--    The user_id index has to be added BEFORE the old unique key is dropped:
--    unique_user_device is what the user_id foreign key relies on, and MySQL
--    refuses to drop it while it is the only index covering that column
--    (error 1553).
ALTER TABLE push_tokens
  ADD KEY idx_push_user (user_id);
ALTER TABLE push_tokens
  DROP INDEX unique_user_device,
  ADD UNIQUE KEY unique_device (device_id);

-- 3. Outbound queue. Notifications fire from the agent-facing API and from
--    inside the scheduler's locked tick, so the send itself must not happen
--    there — enqueuing is a single INSERT and the scheduler drains it under a
--    time budget.
--
--    job_id/client_id are NOT NULL with a 0 sentinel so the unique key can
--    collapse duplicates: MySQL treats NULLs as distinct, which would defeat
--    it for events that carry no job.
CREATE TABLE IF NOT EXISTS push_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(64) NOT NULL,
    user_id INT NOT NULL,
    event VARCHAR(50) NOT NULL,
    job_id INT NOT NULL DEFAULT 0,
    client_id INT NOT NULL DEFAULT 0,
    attempts INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_pending (device_id, event, job_id),
    KEY idx_created (created_at)
) ENGINE=InnoDB;

-- 4. Throttle stamp, mirroring last_emailed_at. A repeat occurrence re-pushes
--    on the same schedule email uses instead of on every single occurrence.
ALTER TABLE notifications
  ADD COLUMN last_pushed_at DATETIME DEFAULT NULL;

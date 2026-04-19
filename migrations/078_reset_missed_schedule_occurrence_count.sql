-- Reset inflated occurrence counts on unresolved missed_schedule notifications.
-- Before the scheduler-level dedup added in 1fdbfc7, SchedulerService called
-- NotificationService::notify() for every overdue schedule on every run (once
-- a minute), which bumped occurrence_count and reset read_at on the single
-- unresolved row. Agents offline for days accumulated thousands of
-- occurrences for what is really one ongoing condition.
--
-- One-time cleanup: clamp the counter to 1 so the UI no longer shows bogus
-- "8434 occurrences" badges. Future increments can't happen — the scheduler
-- now skips notify() entirely when an unresolved missed_schedule already
-- exists for the same (agent, plan).
UPDATE notifications
SET occurrence_count = 1
WHERE type = 'missed_schedule'
  AND resolved_at IS NULL
  AND occurrence_count > 1;

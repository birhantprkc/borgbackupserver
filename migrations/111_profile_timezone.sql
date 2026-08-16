-- Migration 111: a profile states the timezone its run hours are in (#411)
--
-- schedules.timezone is per schedule, set from whoever created the plan. A
-- profile carried a run time but no timezone, so applying one wrote the same
-- "01:00" into schedules that each interpreted it differently — and the list,
-- which renders each schedule in the viewer's zone, then showed the same
-- profile running at 01:00 on some clients and 07:00 on others.
--
-- Null means "the server's timezone", resolved when the profile is applied, so
-- existing profiles keep behaving predictably without anyone editing them.

ALTER TABLE client_profiles
    ADD COLUMN timezone VARCHAR(64) DEFAULT NULL AFTER times;

-- Add timezone column to schedules for UTC-everywhere support
ALTER TABLE schedules ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT 'UTC' AFTER day_of_month;

-- Existing schedules were created in America/New_York context
UPDATE schedules SET timezone = 'America/New_York';

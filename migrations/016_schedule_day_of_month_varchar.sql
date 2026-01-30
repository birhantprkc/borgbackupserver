-- Allow day_of_month to store comma-separated values and "last"
ALTER TABLE schedules MODIFY day_of_month VARCHAR(20) DEFAULT NULL;

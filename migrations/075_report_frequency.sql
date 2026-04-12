ALTER TABLE users ADD COLUMN report_frequency ENUM('daily', 'weekly') NOT NULL DEFAULT 'daily' AFTER daily_report_hour;
ALTER TABLE users ADD COLUMN report_day TINYINT NOT NULL DEFAULT 1 AFTER report_frequency;

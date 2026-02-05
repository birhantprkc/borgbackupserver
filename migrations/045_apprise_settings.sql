-- Add Apprise notification settings
INSERT INTO settings (`key`, `value`) VALUES
    ('apprise_urls', ''),
    ('apprise_on_backup_failed', '1'),
    ('apprise_on_agent_offline', '1'),
    ('apprise_on_storage_low', '1'),
    ('apprise_on_missed_schedule', '0')
ON DUPLICATE KEY UPDATE `key` = `key`;

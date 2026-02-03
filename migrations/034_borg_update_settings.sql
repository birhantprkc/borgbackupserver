-- Add simplified borg update settings
INSERT INTO settings (`key`, `value`) VALUES
    ('borg_update_mode', 'official'),
    ('borg_server_version', ''),
    ('borg_auto_update', '0')
ON DUPLICATE KEY UPDATE `key` = `key`;

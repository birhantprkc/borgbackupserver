-- Add simplified borg update settings
-- Auto-update enabled by default for hands-off operation
INSERT INTO settings (`key`, `value`) VALUES
    ('borg_update_mode', 'official'),
    ('borg_server_version', ''),
    ('borg_auto_update', '1')
ON DUPLICATE KEY UPDATE `key` = `key`;

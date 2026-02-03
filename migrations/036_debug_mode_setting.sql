-- Add debug mode setting (disabled by default for security)
INSERT INTO settings (`key`, `value`) VALUES
    ('debug_mode', '0')
ON DUPLICATE KEY UPDATE `key` = `key`;

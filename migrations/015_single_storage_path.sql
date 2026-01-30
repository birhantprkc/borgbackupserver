-- Migrate from storage_locations table to single storage_path setting
-- The default storage location's path becomes the storage_path setting

INSERT INTO settings (`key`, `value`)
SELECT 'storage_path', path FROM storage_locations WHERE is_default = 1
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- If no default was set, grab the first one
INSERT IGNORE INTO settings (`key`, `value`)
SELECT 'storage_path', path FROM storage_locations ORDER BY id LIMIT 1;

-- Drop the storage_locations table
DROP TABLE IF EXISTS storage_locations;

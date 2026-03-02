-- Storage Locations: allow multiple local storage paths for repositories
-- Each repo can be assigned to a specific storage location (disk/mount point)

CREATE TABLE storage_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(100) NOT NULL,
    path VARCHAR(500) NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Seed default location from current storage_path setting
INSERT INTO storage_locations (label, path, is_default)
SELECT 'Default', `value`, 1 FROM settings WHERE `key` = 'storage_path';

-- Add storage_location_id to repositories (nullable for remote SSH repos)
ALTER TABLE repositories ADD COLUMN storage_location_id INT DEFAULT NULL AFTER storage_type;
ALTER TABLE repositories ADD CONSTRAINT fk_repo_storage_location
    FOREIGN KEY (storage_location_id) REFERENCES storage_locations(id);

-- Backfill existing local repos to the default storage location
UPDATE repositories r
JOIN storage_locations sl ON sl.is_default = 1
SET r.storage_location_id = sl.id
WHERE r.storage_type = 'local';

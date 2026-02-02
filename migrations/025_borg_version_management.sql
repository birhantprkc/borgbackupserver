-- Borg Version Management
-- Centralized borg version control via GitHub binary releases

-- Available borg releases from GitHub
CREATE TABLE borg_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(20) NOT NULL UNIQUE,
    release_tag VARCHAR(30) NOT NULL,
    release_date DATE NOT NULL,
    is_prerelease TINYINT(1) NOT NULL DEFAULT 0,
    release_notes TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_version (version)
) ENGINE=InnoDB;

-- Platform-specific binary assets per release
CREATE TABLE borg_version_assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    borg_version_id INT NOT NULL,
    platform VARCHAR(20) NOT NULL,
    architecture VARCHAR(20) NOT NULL,
    glibc_version VARCHAR(20) DEFAULT NULL,
    asset_name VARCHAR(100) NOT NULL,
    download_url VARCHAR(500) NOT NULL,
    file_size BIGINT DEFAULT NULL,
    FOREIGN KEY (borg_version_id) REFERENCES borg_versions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_asset (borg_version_id, platform, architecture, glibc_version)
) ENGINE=InnoDB;

-- Track borg version that created/last wrote to each repo
ALTER TABLE repositories
    ADD COLUMN borg_version_created VARCHAR(20) DEFAULT NULL,
    ADD COLUMN borg_version_last VARCHAR(20) DEFAULT NULL;

-- New agent columns for install tracking
ALTER TABLE agents
    ADD COLUMN borg_install_method ENUM('package','binary','pip','unknown') DEFAULT 'unknown' AFTER borg_version,
    ADD COLUMN borg_binary_path VARCHAR(255) DEFAULT NULL AFTER borg_install_method;

-- Settings for borg version management
INSERT INTO settings (`key`, `value`) VALUES
    ('target_borg_version', ''),
    ('last_borg_version_check', ''),
    ('fallback_to_pip', '1');

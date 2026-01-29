-- Deduplicated file paths, stored once per agent
CREATE TABLE IF NOT EXISTS file_paths (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    path TEXT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    INDEX idx_agent_name (agent_id, file_name),
    UNIQUE KEY idx_agent_path (agent_id, path(512)),
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=COMPRESSED;

-- File catalog junction table: which files in which archive
CREATE TABLE IF NOT EXISTS file_catalog (
    archive_id INT NOT NULL,
    file_path_id BIGINT UNSIGNED NOT NULL,
    file_size BIGINT DEFAULT 0,
    status ENUM('A','M','U','E') DEFAULT 'U',
    mtime DATETIME NULL,
    PRIMARY KEY (archive_id, file_path_id),
    KEY idx_file_path (file_path_id),
    FOREIGN KEY (archive_id) REFERENCES archives(id) ON DELETE CASCADE,
    FOREIGN KEY (file_path_id) REFERENCES file_paths(id) ON DELETE CASCADE
) ENGINE=InnoDB ROW_FORMAT=COMPRESSED;

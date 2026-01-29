-- File catalog for searchable backup contents
CREATE TABLE IF NOT EXISTS file_catalog (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    archive_id INT NOT NULL,
    agent_id INT NOT NULL,
    file_path TEXT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_size BIGINT DEFAULT 0,
    status ENUM('A','M','U','E') DEFAULT 'U',
    mtime DATETIME NULL,
    INDEX idx_agent_filename (agent_id, file_name),
    INDEX idx_archive (archive_id),
    INDEX idx_agent_path (agent_id, file_name, archive_id),
    FOREIGN KEY (archive_id) REFERENCES archives(id) ON DELETE CASCADE,
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
) ENGINE=InnoDB;

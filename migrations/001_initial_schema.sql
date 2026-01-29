CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE storage_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(100) NOT NULL,
    path VARCHAR(500) NOT NULL,
    max_size_gb INT DEFAULT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE agents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    hostname VARCHAR(255) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    api_key VARCHAR(64) NOT NULL UNIQUE,
    os_info VARCHAR(255) DEFAULT NULL,
    borg_version VARCHAR(20) DEFAULT NULL,
    agent_version VARCHAR(20) DEFAULT NULL,
    status ENUM('setup', 'online', 'offline', 'error') NOT NULL DEFAULT 'setup',
    last_heartbeat DATETIME DEFAULT NULL,
    user_id INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE repositories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    storage_location_id INT DEFAULT NULL,
    name VARCHAR(100) NOT NULL,
    path VARCHAR(500) NOT NULL,
    encryption VARCHAR(50) NOT NULL DEFAULT 'repokey-blake2',
    passphrase_encrypted TEXT DEFAULT NULL,
    size_bytes BIGINT NOT NULL DEFAULT 0,
    archive_count INT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
    FOREIGN KEY (storage_location_id) REFERENCES storage_locations(id) ON DELETE SET NULL
);

CREATE TABLE backup_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    repository_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    directories TEXT NOT NULL,
    advanced_options TEXT DEFAULT NULL,
    prune_minutes INT NOT NULL DEFAULT 0,
    prune_hours INT NOT NULL DEFAULT 0,
    prune_days INT NOT NULL DEFAULT 7,
    prune_weeks INT NOT NULL DEFAULT 4,
    prune_months INT NOT NULL DEFAULT 6,
    prune_years INT NOT NULL DEFAULT 0,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
    FOREIGN KEY (repository_id) REFERENCES repositories(id) ON DELETE CASCADE
);

CREATE TABLE schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    backup_plan_id INT NOT NULL,
    frequency VARCHAR(30) NOT NULL DEFAULT 'daily',
    times VARCHAR(255) DEFAULT NULL,
    day_of_week TINYINT DEFAULT NULL,
    day_of_month TINYINT DEFAULT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    next_run DATETIME DEFAULT NULL,
    last_run DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (backup_plan_id) REFERENCES backup_plans(id) ON DELETE CASCADE
);

CREATE TABLE backup_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    backup_plan_id INT DEFAULT NULL,
    agent_id INT NOT NULL,
    repository_id INT NOT NULL,
    task_type ENUM('backup', 'prune', 'restore', 'check', 'compact') NOT NULL DEFAULT 'backup',
    status ENUM('queued', 'sent', 'running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'queued',
    files_total INT DEFAULT NULL,
    files_processed INT DEFAULT NULL,
    bytes_total BIGINT DEFAULT NULL,
    bytes_processed BIGINT DEFAULT NULL,
    duration_seconds INT DEFAULT NULL,
    error_log TEXT DEFAULT NULL,
    queued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    FOREIGN KEY (backup_plan_id) REFERENCES backup_plans(id) ON DELETE SET NULL,
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
    FOREIGN KEY (repository_id) REFERENCES repositories(id) ON DELETE CASCADE
);

CREATE TABLE archives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    repository_id INT NOT NULL,
    backup_job_id INT DEFAULT NULL,
    archive_name VARCHAR(255) NOT NULL,
    file_count INT NOT NULL DEFAULT 0,
    original_size BIGINT NOT NULL DEFAULT 0,
    deduplicated_size BIGINT NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (repository_id) REFERENCES repositories(id) ON DELETE CASCADE,
    FOREIGN KEY (backup_job_id) REFERENCES backup_jobs(id) ON DELETE SET NULL
);

CREATE TABLE server_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT DEFAULT NULL,
    backup_job_id INT DEFAULT NULL,
    level ENUM('info', 'warning', 'error') NOT NULL DEFAULT 'info',
    message TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE SET NULL,
    FOREIGN KEY (backup_job_id) REFERENCES backup_jobs(id) ON DELETE SET NULL,
    INDEX idx_level (level),
    INDEX idx_created (created_at)
);

CREATE TABLE settings (
    `key` VARCHAR(100) PRIMARY KEY,
    `value` TEXT DEFAULT NULL
);

INSERT INTO settings (`key`, `value`) VALUES
    ('max_queue', '4'),
    ('server_host', ''),
    ('agent_poll_interval', '30'),
    ('smtp_host', ''),
    ('smtp_port', '587'),
    ('smtp_user', ''),
    ('smtp_pass', ''),
    ('smtp_from', '');

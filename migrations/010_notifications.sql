CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('backup_failed', 'agent_offline', 'storage_low', 'missed_schedule') NOT NULL,
    agent_id INT DEFAULT NULL,
    reference_id INT DEFAULT NULL,
    severity ENUM('warning', 'critical') NOT NULL DEFAULT 'warning',
    message TEXT NOT NULL,
    occurrence_count INT NOT NULL DEFAULT 1,
    first_occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
    INDEX idx_unresolved (resolved_at, read_at)
);

INSERT INTO settings (`key`, `value`) VALUES
    ('notification_retention_days', '30'),
    ('storage_alert_threshold', '90'),
    ('email_on_backup_failed', '1'),
    ('email_on_agent_offline', '1'),
    ('email_on_storage_low', '1'),
    ('email_on_missed_schedule', '0');

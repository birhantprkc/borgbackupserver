-- Push notification device registrations.
-- One row per (user, device); re-registration updates the device token.
-- Server-side push delivery consumes this table.
CREATE TABLE IF NOT EXISTS push_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    device_id VARCHAR(64) NOT NULL,
    apns_token VARCHAR(255) NOT NULL,
    platform VARCHAR(16) NOT NULL DEFAULT 'ios',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_device (user_id, device_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

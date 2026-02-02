-- Two-Factor Authentication Support

ALTER TABLE users
    ADD COLUMN totp_secret VARCHAR(255) DEFAULT NULL AFTER timezone,
    ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret,
    ADD COLUMN totp_enabled_at DATETIME DEFAULT NULL AFTER totp_enabled;

CREATE TABLE recovery_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_unused (user_id, used_at)
) ENGINE=InnoDB;

INSERT INTO settings (`key`, `value`) VALUES ('force_2fa', '0');

-- Create notification_services table for per-service notification configuration
CREATE TABLE IF NOT EXISTS notification_services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    service_type VARCHAR(50) NOT NULL,
    apprise_url TEXT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    events JSON NOT NULL,
    last_used_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB;

-- Migrate existing apprise_urls setting to new table (if any)
-- This is handled in PHP since we need to parse multi-line URLs

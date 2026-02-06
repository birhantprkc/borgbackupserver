-- Expand notifications.type from limited ENUM to VARCHAR to support all 12 event types
-- Expand notifications.severity from ENUM to VARCHAR to support 'info' level
ALTER TABLE notifications
    MODIFY COLUMN type VARCHAR(50) NOT NULL,
    MODIFY COLUMN severity VARCHAR(20) NOT NULL DEFAULT 'warning';

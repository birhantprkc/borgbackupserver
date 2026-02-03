-- Track whether borg binary came from official GitHub or server-hosted binaries
ALTER TABLE agents ADD COLUMN borg_source ENUM('official','server','unknown') DEFAULT 'unknown' AFTER borg_install_method;

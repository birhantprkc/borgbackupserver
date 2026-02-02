-- Add platform and architecture columns to agents for borg binary matching
ALTER TABLE agents
    ADD COLUMN platform VARCHAR(20) DEFAULT NULL AFTER glibc_version,
    ADD COLUMN architecture VARCHAR(20) DEFAULT NULL AFTER platform;

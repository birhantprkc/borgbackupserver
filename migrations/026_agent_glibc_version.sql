-- Add glibc_version column to agents for binary matching
ALTER TABLE agents
    ADD COLUMN glibc_version VARCHAR(20) DEFAULT NULL AFTER borg_binary_path;

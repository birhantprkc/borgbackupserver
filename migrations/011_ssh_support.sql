-- SSH support for remote agent backup via borg serve
ALTER TABLE agents
    ADD COLUMN ssh_unix_user VARCHAR(100) DEFAULT NULL AFTER agent_version,
    ADD COLUMN ssh_public_key TEXT DEFAULT NULL AFTER ssh_unix_user,
    ADD COLUMN ssh_private_key_encrypted TEXT DEFAULT NULL AFTER ssh_public_key;

-- Store the server hostname for SSH repo paths
-- (reuses existing 'server_host' setting)

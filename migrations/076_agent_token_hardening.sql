-- Harden agent authentication storage.
ALTER TABLE agents
    ADD COLUMN api_key_hash CHAR(64) DEFAULT NULL AFTER api_key,
    ADD COLUMN api_key_encrypted TEXT DEFAULT NULL AFTER api_key_hash,
    ADD INDEX idx_api_key_hash (api_key_hash);

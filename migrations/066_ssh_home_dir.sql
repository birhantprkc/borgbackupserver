ALTER TABLE agents ADD COLUMN ssh_home_dir VARCHAR(255) DEFAULT NULL AFTER ssh_private_key_encrypted;

-- Backfill existing agents: ssh_home_dir = <storage_path>/<agent_id>
UPDATE agents
SET ssh_home_dir = CONCAT(
    TRIM(TRAILING '/' FROM (SELECT `value` FROM settings WHERE `key` = 'storage_path')),
    '/',
    id
)
WHERE ssh_unix_user IS NOT NULL AND ssh_unix_user != '';

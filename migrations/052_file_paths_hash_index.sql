-- Add path_hash column for fast unique lookups (replaces slow TEXT prefix index)
ALTER TABLE file_paths ADD COLUMN path_hash CHAR(64) NOT NULL DEFAULT '' AFTER file_name;

-- Backfill existing rows (if any)
UPDATE file_paths SET path_hash = SHA2(CONCAT(agent_id, ':', path), 256) WHERE path_hash = '';

-- Add unique index on hash (fast fixed-length comparison)
ALTER TABLE file_paths ADD UNIQUE KEY idx_path_hash (path_hash);

-- Drop the old slow TEXT prefix index
ALTER TABLE file_paths DROP INDEX idx_agent_path;

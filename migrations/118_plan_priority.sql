-- Per-plan CPU and I/O priority for borg on the client: normal, low
-- (nice 10, ionice best-effort 7) or idle (nice 19, ionice idle). The agent
-- applies it; Windows ignores it and macOS applies nice only.
ALTER TABLE backup_plans ADD COLUMN priority VARCHAR(10) NOT NULL DEFAULT 'normal';

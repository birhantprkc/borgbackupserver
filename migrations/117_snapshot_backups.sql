-- Backups from filesystem snapshots (LVM, btrfs, ZFS). The plan carries the
-- switch; the agent reports what its host can snapshot so the plan editor
-- only offers it where it can work.
ALTER TABLE backup_plans ADD COLUMN snapshot TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE agents
    ADD COLUMN snapshot_capable TINYINT(1) DEFAULT NULL,
    ADD COLUMN snapshot_support JSON DEFAULT NULL;

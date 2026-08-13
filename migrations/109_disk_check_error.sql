-- Migration 109: record why a remote host's disk usage could not be read
--
-- The storage page said "provider does not support disk usage queries"
-- whenever the figures were missing, which asserts a cause it never
-- established. A host that has been renamed, a key that no longer works, a
-- firewall in the way and a provider that genuinely has no df all produced the
-- same sentence, and the first three are fixable by the person reading it.

ALTER TABLE remote_ssh_configs
    ADD COLUMN disk_check_error VARCHAR(255) DEFAULT NULL AFTER disk_checked_at;

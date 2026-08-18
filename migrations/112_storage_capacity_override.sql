-- A stated capacity for storage locations whose real size the server cannot
-- see (#415).
--
-- df and statvfs describe the filesystem that answers the syscall, which for a
-- WebDAV/davfs2 mount is the local cache disk, not the remote share. A 100 GB
-- WebDAV target therefore reported the server's own 1.2 TB disk. NFS and CIFS
-- report the export correctly and are unaffected; this is for mounts that
-- cannot answer honestly.
--
-- NULL means "measure it" — the existing behaviour for every local path.

ALTER TABLE storage_locations
    ADD COLUMN capacity_bytes BIGINT DEFAULT NULL AFTER path;

-- Flat per-agent catalog tables (file_catalog_{agent_id}) are created dynamically
-- by CatalogImporter::ensureTable(). This migration marks the schema transition.
-- Data migration from old file_paths/file_catalog tables is handled by
-- bin/migrate-catalog.php (called from bbs-update-run).
--
-- Old tables (file_paths, file_catalog) are preserved for backward compatibility
-- and will be removed in a future release.
SELECT 1;

-- Multiple plugin configs per backup plan: a plan can now run several plugin
-- configs, including two of the same engine (e.g. two MySQL instances).
-- Previously UNIQUE(backup_plan_id, plugin_id) capped it at one config per
-- plugin type. Swap to UNIQUE(backup_plan_id, plugin_config_id) so a plan may
-- link any number of configs, each at most once. Existing rows stay valid and
-- become the pre-checked configs — no data backfill needed.
-- Add the replacement index first: it leads with backup_plan_id, so it keeps
-- the backup_plan_id foreign key covered when the old index is dropped.
ALTER TABLE backup_plan_plugins ADD UNIQUE KEY unique_plan_config (backup_plan_id, plugin_config_id);
ALTER TABLE backup_plan_plugins DROP INDEX unique_plan_plugin;

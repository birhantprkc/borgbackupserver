-- ClickHouse schema for BBS file catalog.
-- Replaces per-agent MySQL/MyISAM tables (file_catalog_{N}, catalog_dirs_{N})
-- with two unified tables partitioned by agent_id.
--
-- Run by bbs-install and bbs-update-run:
--   clickhouse-client -d bbs --multiquery < schema-clickhouse.sql

CREATE TABLE IF NOT EXISTS file_catalog (
    agent_id    UInt32,
    archive_id  UInt32,
    path        String,
    file_name   String,
    parent_dir  String,
    file_size   UInt64,
    status      FixedString(1) DEFAULT 'U',
    mtime       Nullable(DateTime)
) ENGINE = MergeTree()
PARTITION BY agent_id
ORDER BY (agent_id, archive_id, parent_dir, file_name)
SETTINGS index_granularity = 8192;

CREATE TABLE IF NOT EXISTS catalog_dirs (
    agent_id    UInt32,
    archive_id  UInt32,
    dir_path    String,
    parent_dir  String,
    name        String,
    file_count  UInt32,
    total_size  UInt64
) ENGINE = ReplacingMergeTree()
PARTITION BY agent_id
ORDER BY (agent_id, archive_id, parent_dir, name)
SETTINGS index_granularity = 8192;

# MySQL Storage Requirements

BBS catalogs every file from every backup archive into MySQL. This allows you to browse, search, and restore individual files without locking or reading from the borg repository directly — keeping repositories available for concurrent backups and avoiding slow `borg list` operations on large repos.

This page explains how the catalog works, how much space it uses, and how to plan your MySQL storage for large-scale deployments.

---

## Why BBS Catalogs Files in MySQL

Borg repositories are locked during read operations. If you need to browse an archive's contents or search for a file across recovery points, borg must lock the repo, decrypt headers, and walk the archive metadata. On large repositories this can take minutes and blocks any backup that tries to run at the same time.

BBS avoids this entirely by indexing file metadata into MySQL during each backup. Once indexed, all browse, search, and restore-selection operations hit the database instead of the repository. The repository is only accessed at the moment of extraction, and only for the specific files being restored.

---

## How It Works

### Schema

BBS uses two tables with a normalized, deduplicated design:

**`file_paths`** — one row per unique file path per agent (deduplicated across archives):

| Column | Type | Purpose |
|--------|------|---------|
| `id` | BIGINT UNSIGNED | Primary key |
| `agent_id` | INT | Which agent/client owns this path |
| `path` | TEXT | Full file path from the backup |
| `file_name` | VARCHAR(255) | Extracted filename for fast search |

**`file_catalog`** — one row per file per archive (the junction table):

| Column | Type | Purpose |
|--------|------|---------|
| `archive_id` | INT | Which archive contains this file |
| `file_path_id` | BIGINT UNSIGNED | Reference to `file_paths.id` |
| `file_size` | BIGINT | File size in bytes |
| `status` | CHAR(1) | Borg status: A=added, M=modified, U=unchanged, E=error |
| `mtime` | DATETIME | File modification time |

Both tables use `ROW_FORMAT=COMPRESSED` for reduced storage on disk.

### Deduplication

The key design choice is path deduplication. If a server backs up 100,000 files and most of them don't change between backups, the path is stored **once** in `file_paths`. Each archive only adds rows to `file_catalog` referencing existing path IDs. This means:

- `file_paths` grows with the number of **unique files** across all backups for an agent
- `file_catalog` grows with **files × archives**

### Indexing Flow

1. During `borg create`, the agent captures file-status events for every file processed
2. After a successful backup, the agent uploads the file list to the server in batches of 1,000
3. The server upserts paths into `file_paths` (deduplicating) and inserts catalog entries into `file_catalog`
4. When archives are pruned, cascading deletes remove the corresponding `file_catalog` rows automatically

---

## Storage Estimates

### Per-Row Sizes (Compressed)

Based on InnoDB `ROW_FORMAT=COMPRESSED` with typical file paths (average ~80 characters):

| Table | Uncompressed Row | Compressed Row (est.) |
|-------|------------------|-----------------------|
| `file_paths` | ~120–200 bytes | ~70–120 bytes |
| `file_catalog` | ~40 bytes | ~25 bytes |

Compression ratios vary with path length and pattern similarity, but 40–60% reduction is typical for path-heavy data.

### Estimation Formula

For a single agent backing up **F** unique files with **A** archives (recovery points) retained:

| Component | Row Count | Estimated Size (compressed) |
|-----------|-----------|----------------------------|
| `file_paths` | F | F × ~100 bytes |
| `file_catalog` | F × A | F × A × ~25 bytes |
| **Total** | | F × 100 + (F × A × 25) bytes |

### Example Scenarios

| Scenario | Unique Files (F) | Archives (A) | `file_paths` | `file_catalog` | Total |
|----------|-----------------|--------------|-------------|----------------|-------|
| Small server | 50,000 | 10 | ~5 MB | ~12 MB | **~17 MB** |
| Medium server | 200,000 | 20 | ~19 MB | ~95 MB | **~114 MB** |
| Large server | 1,000,000 | 20 | ~95 MB | ~475 MB | **~570 MB** |
| Large server | 1,000,000 | 50 | ~95 MB | ~1.2 GB | **~1.3 GB** |

### Multi-Server Deployments

For **N** agents, multiply accordingly. Examples with 20 retained archives each:

| Agents | Files per Agent | Total Catalog Size |
|--------|----------------|--------------------|
| 5 | 50,000 | ~85 MB |
| 10 | 200,000 | ~1.1 GB |
| 20 | 200,000 | ~2.3 GB |
| 10 | 1,000,000 | ~5.7 GB |
| 50 | 200,000 | ~5.7 GB |
| 50 | 1,000,000 | ~28.5 GB |

---

## MySQL Configuration Recommendations

### InnoDB Buffer Pool

The most important setting. The buffer pool caches table data and indexes in memory. For catalog-heavy workloads, size it to hold most of your catalog data:

```ini
# /etc/mysql/mysql.conf.d/mysqld.cnf

# Small deployments (< 1 GB catalog): default is usually fine
innodb_buffer_pool_size = 256M

# Medium deployments (1–5 GB catalog)
innodb_buffer_pool_size = 1G

# Large deployments (5–30 GB catalog)
innodb_buffer_pool_size = 4G
```

A good rule of thumb: set the buffer pool to at least 50% of your total catalog size, or 50–70% of available server RAM (whichever is less).

### Disk Space

Ensure the MySQL data directory has enough space for:

- The catalog tables (see estimates above)
- InnoDB overhead (~10–20% on top of raw data)
- Temporary tables during large queries
- Binary logs if replication is enabled

For a 50-agent deployment with 200k files each, plan for at least **5 GB** of MySQL data directory space for the catalog alone.

### Other Settings

```ini
# Increase max packet size for large catalog batch inserts
max_allowed_packet = 64M

# Increase temp table size for large browse/search queries
tmp_table_size = 64M
max_heap_table_size = 64M
```

---

## Monitoring Catalog Size

You can check your current catalog size directly in MySQL:

```sql
SELECT
    table_name,
    table_rows AS estimated_rows,
    ROUND(data_length / 1024 / 1024, 1) AS data_mb,
    ROUND(index_length / 1024 / 1024, 1) AS index_mb,
    ROUND((data_length + index_length) / 1024 / 1024, 1) AS total_mb
FROM information_schema.tables
WHERE table_schema = 'bbs'
  AND table_name IN ('file_paths', 'file_catalog')
ORDER BY table_name;
```

---

## Reducing Catalog Size

If the catalog grows larger than expected:

- **Reduce retention** — fewer archives means fewer `file_catalog` rows. Pruning an archive automatically deletes its catalog entries via cascading foreign keys.
- **Exclude noisy directories** — directories with many transient files (caches, logs, temp files) inflate the catalog. Use borg exclude patterns in your backup templates to skip them.
- **Run `OPTIMIZE TABLE`** — after large prune operations, InnoDB may not immediately reclaim disk space. Running `OPTIMIZE TABLE file_catalog; OPTIMIZE TABLE file_paths;` will rebuild and compress the tables. This locks the tables briefly, so run it during a maintenance window.

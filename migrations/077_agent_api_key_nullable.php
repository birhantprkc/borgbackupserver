<?php
// Allow api_key to be NULL and drop the legacy UNIQUE constraint on upgrade.
// Fresh installs already have the new schema — each operation is attempted
// independently so missing state doesn't block the migration.

try {
    $db->getPdo()->exec("ALTER TABLE agents MODIFY COLUMN api_key VARCHAR(64) DEFAULT NULL");
} catch (\Throwable $e) { /* already nullable */ }

try {
    // In MariaDB/MySQL the inline UNIQUE constraint creates an index named
    // after the column. No-op if it never existed.
    $db->getPdo()->exec("ALTER TABLE agents DROP INDEX api_key");
} catch (\Throwable $e) { /* no such index */ }

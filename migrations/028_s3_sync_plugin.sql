-- Register s3_sync plugin
INSERT INTO plugins (slug, name, description, plugin_type, is_active)
VALUES ('s3_sync', 'S3 Offsite Sync', 'Sync repositories to S3-compatible storage after prune', 'post_backup', 1);

-- Global S3 settings
INSERT IGNORE INTO settings (`key`, `value`) VALUES
    ('s3_endpoint', ''),
    ('s3_region', ''),
    ('s3_bucket', ''),
    ('s3_access_key', ''),
    ('s3_secret_key', ''),
    ('s3_path_prefix', '');

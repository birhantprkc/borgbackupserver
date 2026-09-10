-- The S3 Offsite Sync plugin can now copy a repository to an SSH host
-- (SFTP) or a second local disk as well as an S3 bucket (#413). Existing
-- configs have no target_type and keep working as S3.
UPDATE plugins SET
    name = 'Offsite Sync',
    description = 'Copies each repository to another storage after backup and prune: an S3-compatible bucket, a host over SSH (SFTP), or a second local disk. Keeps a manifest beside the copy for fast restore.'
WHERE slug = 's3_sync';

-- Update Interworx template with better default paths
UPDATE backup_templates
SET directories = '/chroot/home\n/var\n/etc\n/usr/local\n/root',
    excludes = '*.tmp\n*.log\n*.cache'
WHERE name = 'Interworx Server';

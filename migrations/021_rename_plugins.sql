-- Rename plugins to Backup/Restore naming
UPDATE plugins SET name = 'MySQL Backup/Restore', description = 'Dumps MySQL databases before each backup, storing them in the repository for easy one-click restore back to the server.' WHERE slug = 'mysql_dump';
UPDATE plugins SET name = 'PostgreSQL Backup/Restore', description = 'Dumps PostgreSQL databases before each backup, storing them in the repository for easy one-click restore back to the server.' WHERE slug = 'pg_dump';

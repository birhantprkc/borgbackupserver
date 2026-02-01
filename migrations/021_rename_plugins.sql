-- Rename plugins to Backup/Restore naming
UPDATE plugins SET name = 'MySQL Backup/Restore', description = 'Performs mysqldump before backup and optionally restores databases back to the MySQL server.' WHERE slug = 'mysql_dump';
UPDATE plugins SET name = 'PostgreSQL Backup/Restore', description = 'Performs pg_dump before backup and optionally restores databases back to the PostgreSQL server.' WHERE slug = 'pg_dump';

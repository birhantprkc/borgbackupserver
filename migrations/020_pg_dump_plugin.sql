-- Add PostgreSQL dump plugin
INSERT INTO plugins (slug, name, description, plugin_type) VALUES
('pg_dump', 'PostgreSQL Database Dump', 'Dumps PostgreSQL databases to a local directory before backup. Supports per-database dumps with optional cleanup after backup completion.', 'pre_backup');

INSERT IGNORE INTO plugins (slug, name, description, plugin_type) VALUES
('interworx', 'InterWorx Backup', 'Runs InterWorx control panel backups before each borg archive. Supports full, partial (web/mail/db), and structure-only backup modes for all domains.', 'pre_backup');

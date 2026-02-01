-- Add Shell Script Hook plugin
INSERT INTO plugins (slug, name, description, plugin_type) VALUES
('shell_hook', 'Shell Script Hook', 'Runs custom shell scripts on the client before and/or after backup. Useful for application quiescing, cache clearing, notifications, or custom integrations.', 'pre_backup');

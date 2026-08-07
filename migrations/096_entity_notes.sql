-- Team notes on entities (#334): free-text "what is this for" notes on
-- clients, repositories, plugin configs, and backup plans, so intent
-- survives across a team of admins. UI shows nothing unless a note exists.
ALTER TABLE agents
    ADD COLUMN notes TEXT DEFAULT NULL,
    ADD COLUMN notes_updated_by INT DEFAULT NULL,
    ADD COLUMN notes_updated_at DATETIME DEFAULT NULL,
    ADD CONSTRAINT fk_agents_notes_by FOREIGN KEY (notes_updated_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE repositories
    ADD COLUMN notes TEXT DEFAULT NULL,
    ADD COLUMN notes_updated_by INT DEFAULT NULL,
    ADD COLUMN notes_updated_at DATETIME DEFAULT NULL,
    ADD CONSTRAINT fk_repositories_notes_by FOREIGN KEY (notes_updated_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE plugin_configs
    ADD COLUMN notes TEXT DEFAULT NULL,
    ADD COLUMN notes_updated_by INT DEFAULT NULL,
    ADD COLUMN notes_updated_at DATETIME DEFAULT NULL,
    ADD CONSTRAINT fk_plugin_configs_notes_by FOREIGN KEY (notes_updated_by) REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE backup_plans
    ADD COLUMN notes TEXT DEFAULT NULL,
    ADD COLUMN notes_updated_by INT DEFAULT NULL,
    ADD COLUMN notes_updated_at DATETIME DEFAULT NULL,
    ADD CONSTRAINT fk_backup_plans_notes_by FOREIGN KEY (notes_updated_by) REFERENCES users(id) ON DELETE SET NULL;

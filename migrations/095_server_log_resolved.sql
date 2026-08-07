-- Resolvable errors (#365): admins can mark an error as resolved so it
-- drops off the dashboard error tile/list/chart and the default log view —
-- multiple admins doing backup checks stop re-triaging the same handled
-- error. resolved_by references the resolving user for the audit trail.
ALTER TABLE server_log
    ADD COLUMN resolved_at DATETIME DEFAULT NULL,
    ADD COLUMN resolved_by INT DEFAULT NULL,
    ADD CONSTRAINT fk_server_log_resolved_by FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL;

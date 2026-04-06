-- OIDC SSO Support
ALTER TABLE users
    ADD COLUMN auth_provider ENUM('local', 'oidc') NOT NULL DEFAULT 'local' AFTER totp_enabled_at,
    ADD COLUMN oidc_status ENUM('active', 'pending') NOT NULL DEFAULT 'active' AFTER auth_provider;

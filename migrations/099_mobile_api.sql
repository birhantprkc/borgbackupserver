-- Token-authenticated API support:
-- 1. api_tokens grows device/expiry metadata for kind='mobile' tokens
--    minted by password or SSO login through the API.
-- 2. auth_challenges stores short-lived single-use secrets for the
--    stateless mobile auth flows: the 2FA challenge between password
--    and TOTP verification, and the OIDC exchange code between the
--    browser redirect and the token exchange.
ALTER TABLE api_tokens
  ADD COLUMN device_name  VARCHAR(100) DEFAULT NULL,
  ADD COLUMN device_id    VARCHAR(64)  DEFAULT NULL,
  ADD COLUMN expires_at   DATETIME     DEFAULT NULL,
  ADD COLUMN last_seen_ip VARCHAR(45)  DEFAULT NULL,
  ADD INDEX idx_kind_user (kind, user_id);

CREATE TABLE IF NOT EXISTS auth_challenges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('2fa', 'oidc_exchange') NOT NULL,
    challenge_hash CHAR(64) NOT NULL UNIQUE,
    user_id INT NOT NULL,
    payload TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

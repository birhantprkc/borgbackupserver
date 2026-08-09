-- Mobile profile API (#bbsapp): pending 2FA enrolment secrets.
--
-- The web 2FA setup flow parks the not-yet-confirmed secret in
-- $_SESSION['2fa_setup_secret'] between "show me the QR" and "here is my
-- first code". Token-authenticated callers have no session, so the pending
-- secret lives here instead — keyed to the user, short-lived and single-use,
-- exactly like the existing '2fa' login challenge. Storing it server-side
-- means /profile/2fa/enable never has to trust a secret handed back by the
-- client.
ALTER TABLE auth_challenges
  MODIFY COLUMN kind ENUM('2fa', 'oidc_exchange', '2fa_setup') NOT NULL;

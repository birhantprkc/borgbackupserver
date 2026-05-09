-- Apprise email URLs need an explicit ?mode= parameter to indicate the
-- TLS mode. Without it, apprise's default for `mailto://` falls through
-- to INSECURE and sends SMTP AUTH before STARTTLS, which submission
-- servers like AWS SES on port 587 reject with "530 Must issue a
-- STARTTLS command first". The wizard now always emits ?mode=, but
-- pre-existing rows may not have it. Backfill with a port-based
-- heuristic that matches typical SMTP conventions:
--   :465 → ssl (implicit TLS)
--   :25  → insecure
--   anything else (most commonly :587) → starttls
UPDATE notification_services
SET apprise_url = CONCAT(
    apprise_url,
    CASE WHEN apprise_url LIKE '%?%' THEN '&' ELSE '?' END,
    'mode=',
    CASE
        WHEN apprise_url REGEXP ':465(/|\\\\?|$)' THEN 'ssl'
        WHEN apprise_url REGEXP ':25(/|\\\\?|$)'  THEN 'insecure'
        ELSE 'starttls'
    END
)
WHERE service_type IN ('mailto', 'mailtos')
  AND apprise_url NOT LIKE '%mode=%';

-- Branding images (navbar icon, login logo, app icon) are stored as base64
-- PNG strings in settings.value. The column was TEXT (max 65 KiB), which is
-- too small for the resized images we accept (up to 800x800 for the login
-- logo) — the upload failed with "Data too long for column 'value'" (#238).
-- MEDIUMTEXT raises the cap to 16 MiB, far above any reasonable PNG.

ALTER TABLE settings MODIFY `value` MEDIUMTEXT DEFAULT NULL;

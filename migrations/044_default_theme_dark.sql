ALTER TABLE users ALTER COLUMN theme SET DEFAULT 'dark';
UPDATE users SET theme = 'dark' WHERE theme = 'light';

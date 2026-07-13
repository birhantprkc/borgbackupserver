-- Archive lock / legal hold (#314). A locked archive is renamed with the
-- "locked." prefix so borg prune's --glob-archives filter can never
-- select it (repo/plan prefixes are sanitized to letters, numbers,
-- hyphens, and underscores — a dot is unreachable). The flag here is the
-- source of truth for the UI and BBS-side delete guards.
ALTER TABLE archives ADD COLUMN locked TINYINT(1) NOT NULL DEFAULT 0;

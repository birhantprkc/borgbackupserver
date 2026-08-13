-- Migration 107: Client Profiles
--
-- A profile describes a kind of machine — DB servers, laptops, registers — and
-- carries the settings a new client of that kind should start with: what to
-- back up (via a template), how often, how long to keep it, and how patient to
-- be when the client drops out mid-backup.
--
-- It is a model, not a binding. Editing a profile deliberately leaves existing
-- clients alone; there is a separate, explicit action for pushing changes down
-- to everything in the profile.

CREATE TABLE client_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',

    -- What to back up. Null template = no directory defaults, plan is authored
    -- from scratch.
    template_id INT DEFAULT NULL,

    -- When. Mirrors the schedules table so a plan can be built straight from it.
    frequency VARCHAR(30) NOT NULL DEFAULT 'daily',
    times VARCHAR(255) DEFAULT '02:00',
    day_of_week TINYINT DEFAULT NULL,
    day_of_month VARCHAR(20) DEFAULT NULL,

    -- How long to keep it. Same shape as backup_plans.
    prune_minutes INT NOT NULL DEFAULT 0,
    prune_hours INT NOT NULL DEFAULT 0,
    prune_days INT NOT NULL DEFAULT 7,
    prune_weeks INT NOT NULL DEFAULT 4,
    prune_months INT NOT NULL DEFAULT 6,
    prune_years INT NOT NULL DEFAULT 0,

    -- Failure handling, per profile rather than per install. Null means "use
    -- the global setting" — a laptop that sleeps and a database server on a
    -- wired LAN want very different patience, but most profiles want neither
    -- and should follow the server default rather than pin a copy of it.
    auto_retry_max_attempts INT DEFAULT NULL,
    job_offline_grace_minutes INT DEFAULT NULL,
    auto_retry_backoff_minutes INT DEFAULT NULL,

    -- The profile new clients land in, and the one that cannot be deleted.
    is_default TINYINT(1) NOT NULL DEFAULT 0,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_profile_name (name),
    FOREIGN KEY (template_id) REFERENCES backup_templates(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO client_profiles (name, description, is_default)
VALUES ('Default', 'Every client starts here until it is given a profile of its own.', 1);

ALTER TABLE agents
    ADD COLUMN client_profile_id INT DEFAULT NULL AFTER user_id,
    ADD FOREIGN KEY (client_profile_id) REFERENCES client_profiles(id) ON DELETE SET NULL;

-- Existing clients join the default profile, so the list is never "unassigned".
UPDATE agents SET client_profile_id = (SELECT id FROM client_profiles WHERE is_default = 1 LIMIT 1);

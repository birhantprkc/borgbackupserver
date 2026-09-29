-- Where a client is (data centre, office, rack), for organizations with
-- machines in several places (#478).
ALTER TABLE agents ADD COLUMN location VARCHAR(100) DEFAULT NULL AFTER os_info;

-- Per-client switch for the "client offline" alert. Laptops and desktops that
-- are switched off by design can be excluded without silencing servers (#512).
ALTER TABLE agents ADD COLUMN offline_alerts TINYINT(1) NOT NULL DEFAULT 1;

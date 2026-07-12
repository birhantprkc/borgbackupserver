-- Wake-on-LAN client setting (#326). The server sends a magic packet to
-- wake a sleeping client when backup work is queued for it. mac_address
-- is agent-reported (prefills the WoL MAC field); wol_mac is the value
-- the admin confirmed/entered. Only works when the BBS server is on the
-- same network as the client.
ALTER TABLE agents
    ADD COLUMN mac_address VARCHAR(17) DEFAULT NULL AFTER ip_address,
    ADD COLUMN wol_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER server_host_override,
    ADD COLUMN wol_mac VARCHAR(17) DEFAULT NULL AFTER wol_enabled,
    ADD COLUMN wol_broadcast VARCHAR(45) DEFAULT NULL AFTER wol_mac,
    ADD COLUMN wol_timeout_minutes INT NOT NULL DEFAULT 5 AFTER wol_broadcast;

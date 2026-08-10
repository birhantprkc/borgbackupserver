-- Let an agent decline server-driven updates (#387).
--
-- A containerised agent ships inside its image, so updating the running copy
-- in place is both pointless and misleading: the next container restart
-- reverts it. Those agents report auto_update_enabled = 0 at check-in and the
-- server then leaves them out of the outdated list, the upgrade prompts and
-- the automatic update sweep.
--
-- Defaults to 1 so every existing agent keeps its current behaviour until it
-- checks in and says otherwise.
ALTER TABLE agents
  ADD COLUMN auto_update_enabled TINYINT(1) NOT NULL DEFAULT 1;

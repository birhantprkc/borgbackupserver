-- Add ssh_port setting for Docker multi-tenant deployments
-- Default is 22 (standard SSH), Docker tenants will use 2200 + tenant_id

INSERT INTO settings (`key`, `value`) VALUES ('ssh_port', '22')
ON DUPLICATE KEY UPDATE `value` = `value`;

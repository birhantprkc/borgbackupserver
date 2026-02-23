-- Server backup settings (configurable from Settings > General)
INSERT IGNORE INTO settings (`key`, `value`) VALUES ('self_backup_enabled', '1');
INSERT IGNORE INTO settings (`key`, `value`) VALUES ('self_backup_retention', '7');
INSERT IGNORE INTO settings (`key`, `value`) VALUES ('self_backup_catalogs', '0');

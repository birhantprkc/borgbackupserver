-- Change file_catalog status from ENUM to CHAR(1) to support all borg status codes
ALTER TABLE file_catalog MODIFY COLUMN status CHAR(1) DEFAULT 'U';

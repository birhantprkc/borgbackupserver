-- Backup templates for common server roles
CREATE TABLE IF NOT EXISTS backup_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT '',
    directories TEXT NOT NULL,
    excludes TEXT DEFAULT NULL,
    advanced_options TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Add excludes column to backup_plans
ALTER TABLE backup_plans ADD COLUMN excludes TEXT DEFAULT NULL AFTER directories;

-- Seed default templates
INSERT INTO backup_templates (name, description, directories, excludes) VALUES
('Web Server', 'Apache/Nginx web server with virtual hosts', '/var/www\n/etc/nginx\n/etc/apache2\n/etc/httpd\n/etc/letsencrypt\n/home', '*.tmp\n*.log\n*.cache\n/home/*/tmp\n/home/*/.cache'),
('Database Server (MySQL)', 'MySQL/MariaDB database server', '/var/lib/mysql\n/etc/mysql\n/etc/my.cnf.d\n/root', '*.tmp\n*.pid\n*.sock'),
('Database Server (PostgreSQL)', 'PostgreSQL database server', '/var/lib/postgresql\n/etc/postgresql\n/root', '*.tmp\n*.pid\n*.sock'),
('Mail Server', 'Email server with mailboxes', '/var/mail\n/var/vmail\n/etc/postfix\n/etc/dovecot\n/etc/opendkim', '*.tmp\n*.log'),
('Interworx Server', 'Interworx hosting control panel', '/home\n/var/lib/mysql\n/etc\n/var/www\n/usr/local/interworx', '*.tmp\n*.log\n*.cache\n/home/*/tmp\n/home/*/.cache\n/home/*/mail/.Trash'),
('File Server', 'General purpose file/NAS server', '/home\n/srv\n/opt\n/var/shared', '*.tmp\n*.cache\nThumbs.db\n.DS_Store'),
('Docker Host', 'Docker/container host', '/opt\n/srv\n/home\n/etc\n/var/lib/docker/volumes', '*.tmp\n*.log\n/var/lib/docker/overlay2'),
('Minimal (System Config)', 'Essential system configuration only', '/etc\n/root\n/home\n/var/spool/cron', '*.tmp\n*.log\n*.cache');

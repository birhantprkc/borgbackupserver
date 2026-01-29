# Borg Backup Server — Installation Guide

This guide covers installing the BBS server on a fresh Linux system. The server is a PHP web application backed by MySQL.

---

## Requirements

- **OS:** Ubuntu 22.04+ / Debian 12+ / RHEL 9+ / Rocky 9+ (any Linux with PHP 8.1+)
- **PHP:** 8.1 or newer with extensions: pdo_mysql, mbstring, openssl, json
- **MySQL:** 8.0+ or MariaDB 10.6+
- **Composer:** 2.x
- **Web server:** Apache or Nginx (or PHP built-in for development)
- **BorgBackup:** Installed on the server (for SSH-based backups, server-side prune, and download/restore)
- **OpenSSH Server:** `sshd` running and accepting connections (agents connect via SSH for borg)
- **Optional:** Memcached + php-memcached extension (for dashboard caching)

---

## 1. Install System Packages

### Ubuntu / Debian

```bash
apt update
apt install -y php8.3 php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl \
    mysql-server borgbackup composer git

# Optional: Memcached
apt install -y memcached php8.3-memcached
```

### RHEL / Rocky / AlmaLinux

```bash
dnf install -y epel-release
dnf install -y php php-mysqlnd php-mbstring php-xml php-json \
    mysql-server borgbackup composer git

systemctl enable --now mysqld
```

---

## 2. Create the Database

```bash
mysql -u root -p <<'SQL'
CREATE DATABASE bbs CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bbs'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON bbs.* TO 'bbs'@'localhost';
FLUSH PRIVILEGES;
SQL
```

---

## 3. Download BBS

```bash
cd /var/www
git clone https://github.com/marcpope/borgbackupserver.git bbs
cd bbs
composer install --no-dev
```

---

## 4. Configure Environment

```bash
cp config/.env.example config/.env
```

Edit `config/.env`:

```ini
APP_NAME="Borg Backup Server"
APP_URL=https://backups.example.com
APP_ENV=production
APP_DEBUG=false

DB_HOST=localhost
DB_NAME=bbs
DB_USER=bbs
DB_PASS=your_secure_password

SESSION_LIFETIME=3600

# Generate with: php -r "echo bin2hex(random_bytes(32));"
APP_KEY=
```

Generate the encryption key:

```bash
php -r "echo 'APP_KEY=' . bin2hex(random_bytes(32)) . PHP_EOL;" >> config/.env
```

The `APP_KEY` is used to encrypt repository passphrases at rest (AES-256-GCM). Keep it safe — if lost, encrypted passphrases cannot be recovered.

---

## 5. Import Database Schema

```bash
mysql -u root -p bbs < schema.sql
```

This creates all database tables, seeds the default admin user, and populates backup templates.

**Default login:** `admin` / `admin` — change the password immediately after first login.

---

## 6. Create Storage Location

Create a directory where borg repositories will be stored:

```bash
mkdir -p /mnt/backups
chown www-data:www-data /mnt/backups
```

After logging in, go to **Settings > Storage Locations** and add the path.

---

## 7. Web Server Configuration

### Option A: Apache

```bash
apt install -y libapache2-mod-php8.3
a2enmod rewrite
```

Create `/etc/apache2/sites-available/bbs.conf`:

```apache
<VirtualHost *:443>
    ServerName backups.example.com
    DocumentRoot /var/www/bbs/public

    <Directory /var/www/bbs/public>
        AllowOverride All
        Require all granted
    </Directory>

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/backups.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/backups.example.com/privkey.pem
</VirtualHost>
```

```bash
a2ensite bbs
systemctl restart apache2
```

### Option B: Nginx

```nginx
server {
    listen 443 ssl;
    server_name backups.example.com;
    root /var/www/bbs/public;
    index index.php;

    ssl_certificate /etc/letsencrypt/live/backups.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/backups.example.com/privkey.pem;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\. {
        deny all;
    }
}
```

```bash
systemctl restart nginx php8.3-fpm
```

### SSL Certificate

```bash
apt install -y certbot
certbot certonly --standalone -d backups.example.com
```

**SSL is required.** Agents communicate over HTTPS and send API keys in headers.

---

## 8. Set Up SSH Helper

Agents back up over SSH using `borg serve`. BBS needs a helper script to create restricted Unix users when clients are added. Install it:

```bash
cp bin/bbs-ssh-helper /usr/local/bin/bbs-ssh-helper
chmod 755 /usr/local/bin/bbs-ssh-helper
```

Allow the web server user to run it via sudo (no password):

```bash
echo "www-data ALL=(root) NOPASSWD: /usr/local/bin/bbs-ssh-helper" > /etc/sudoers.d/bbs-ssh-helper
chmod 440 /etc/sudoers.d/bbs-ssh-helper
```

Ensure OpenSSH server is installed and running:

```bash
apt install -y openssh-server
systemctl enable --now sshd
```

The helper script only manages users with the `bbs-` prefix and validates all inputs. See [Agent Deployment Guide — SSH Architecture](AGENT.md#ssh-architecture) for full details.

---

## 9. Set Up the Scheduler

The scheduler checks for due backups and processes the job queue. Add a cron entry:

```bash
crontab -e
```

Add:

```
* * * * * php /var/www/bbs/scheduler.php >> /var/log/bbs-scheduler.log 2>&1
```

This runs every minute and:
1. Marks agents offline if their heartbeat is stale
2. Creates queued jobs for any schedules that are due
3. Promotes queued jobs to sent (up to `max_queue` concurrently)
4. Executes server-side prune/compact jobs locally (agents are append-only)
5. Checks storage locations for low disk space

---

## 10. File Permissions

```bash
chown -R www-data:www-data /var/www/bbs
chmod 600 /var/www/bbs/config/.env
chmod 700 /mnt/backups
```

The web server user (`www-data`) needs:
- Read access to the application code
- Read/write to `config/.env`
- Read/write to the storage location(s) where borg repos live
- Execute access to `borg` binary (for download/restore features)

---

## 11. Post-Install Checklist

1. Log in as `admin` / `admin`
2. **Change the admin password** (top-right dropdown > Profile)
3. Go to **Settings** and configure:
   - **Server Host** — the hostname/IP agents will use to reach this server (used for both HTTPS and SSH)
   - **Max Concurrent Jobs** — how many backups can run simultaneously (default 4)
   - **SMTP settings** — for failure notification emails (optional)
4. Add a **Storage Location** (e.g. `/mnt/backups`)
5. Create a **Client** — this generates an API key and install command
6. Run the install command on your first endpoint (see [Agent Deployment Guide](AGENT.md))

---

## Development Server

For local development, the built-in PHP server works:

```bash
cd /var/www/bbs/public
php -S localhost:8080
```

---

## Upgrading

```bash
cd /var/www/bbs
git pull
composer install --no-dev
```

Check the release notes for any schema changes that need to be applied.

---

## Troubleshooting

| Problem | Solution |
|---|---|
| Blank page | Set `APP_DEBUG=true` in `.env`, check PHP error log |
| Database connection failed | Verify DB_HOST, DB_NAME, DB_USER, DB_PASS in `.env` |
| 404 on all routes | Enable Apache `mod_rewrite` or check Nginx `try_files` |
| Scheduler not running | Check `crontab -l`, verify path to `scheduler.php` |
| Agents can't connect (HTTPS) | Ensure SSL is configured and port 443 is open |
| Agents can't connect (SSH) | Ensure `sshd` is running and port 22 is open |
| SSH provisioning fails | Check `bbs-ssh-helper` is at `/usr/local/bin/`, sudoers is configured, web user matches |
| Borg not found | Install borg on the server: `apt install borgbackup` |
| Prune not running | Prune runs server-side in the scheduler — check cron and server borg install |
| Memcached not working | App works without it (graceful fallback), install `php-memcached` to enable |

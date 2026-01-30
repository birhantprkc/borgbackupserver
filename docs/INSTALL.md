# Borg Backup Server — Installation Guide

This guide covers installing the BBS server on a fresh Linux system. The server is a PHP web application backed by MySQL with a built-in setup wizard.

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
apt install -y php php-mysql php-mbstring php-xml php-curl \
    mysql-server borgbackup composer git memcached php-memcached
```

### RHEL / Rocky / AlmaLinux

```bash
dnf install -y epel-release
dnf install -y php php-mysqlnd php-mbstring php-xml php-json \
    mysql-server borgbackup openssh-server composer git

systemctl enable --now mysqld sshd
```

---

## 2. Create MySQL User

The setup wizard will create the database and tables automatically. You only need a MySQL user with permission to create databases:

```bash
mysql -u root -p <<'SQL'
CREATE USER 'bbs'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON *.* TO 'bbs'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
SQL
```

If you prefer to limit privileges, create the database first and grant only on it:

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

## 4. Set Up SSH Helper

Agents back up over SSH using `borg serve`. BBS needs a helper script to create restricted Unix users when clients are added:

```bash
cp bin/bbs-ssh-helper /usr/local/bin/bbs-ssh-helper
chmod 755 /usr/local/bin/bbs-ssh-helper
```

Allow the web server user to run it via sudo (no password):

```bash
echo "www-data ALL=(root) NOPASSWD: /usr/local/bin/bbs-ssh-helper" > /etc/sudoers.d/bbs-ssh-helper
chmod 440 /etc/sudoers.d/bbs-ssh-helper
```

The helper script only manages users with the `bbs-` prefix and validates all inputs. See [Agent Deployment Guide — SSH Architecture](AGENT.md#ssh-architecture) for full details.

---

## 5. Web Server Configuration

### Option A: Apache

```bash
apt install -y libapache2-mod-php
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
a2enmod ssl
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

## 6. File Permissions

```bash
chown -R www-data:www-data /var/www/bbs
chmod 755 /var/www/bbs/config
```

The web server user (`www-data`) needs:
- Read access to the application code
- Write access to `config/` (the setup wizard creates `.env` here)
- Execute access to `borg` binary (for download/restore and server-side prune)
- Sudo access to `bbs-ssh-helper` (configured in step 4)

---

## 7. Run the Setup Wizard

Open your browser and navigate to your BBS server URL (e.g., `https://backups.example.com`). Since no `config/.env` file exists yet, the setup wizard will start automatically.

### Step 1: Welcome

The wizard checks system requirements:
- PHP version (8.1+)
- Required PHP extensions (pdo_mysql, mbstring, openssl)
- Config directory is writable

All requirements must pass before you can continue.

### Step 2: Database

Enter your MySQL connection details:
- **Database Host** — usually `localhost`
- **Database Name** — the wizard creates it if it doesn't exist
- **Database User** — the MySQL user from step 2
- **Database Password**

The wizard tests the connection before proceeding. If it fails, you'll see the MySQL error message inline.

### Step 3: Admin Account

Create your administrator login:
- **Username** — your login name
- **Email** — for password reset and notification delivery
- **Password** — minimum 8 characters

This replaces the default `admin`/`admin` account — you'll log in with the credentials you choose here.

### Step 4: Storage & Server

Configure where backups are stored and how agents connect:
- **Storage Label** — a display name (e.g., "Primary Storage")
- **Storage Path** — absolute filesystem path where borg repos will live (e.g., `/mnt/backups`). The wizard will attempt to create it if it doesn't exist.
- **Server Hostname** — the address agents use for both HTTPS polling and SSH backup connections (e.g., `backups.example.com`). Pre-filled from your browser's current host.

### Step 5: Review & Install

Review all your settings. The summary also shows whether the SSH helper script is installed. Click **Install** to:
1. Generate the `APP_KEY` encryption key
2. Create the database tables and seed data
3. Create your admin account
4. Register the storage location
5. Set the server hostname
6. Write the `config/.env` file

### Step 6: Complete

You'll see a success page with two options:
- **Add Your First Client** — goes directly to the client creation page to set up your first agent
- **Go to Dashboard** — takes you to the main dashboard

---

## 8. Set Up the Scheduler

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

## 9. Post-Install Checklist

After completing the setup wizard:

1. **Add your first client** — create it in the web UI, then run the agent install command on your endpoint (see [Agent Deployment Guide](AGENT.md))
2. **Create a repository** on the client detail page (Repos tab)
3. **Create a backup plan** with a schedule (Schedules tab)
4. **Verify the scheduler** is running: `tail -f /var/log/bbs-scheduler.log`
5. **Optional:** Configure SMTP for email alerts (Settings > Notifications)
6. **Optional:** Adjust max concurrent jobs (Settings > General)

---

## Manual Setup (Without Wizard)

If you prefer to configure BBS manually without the wizard:

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

Import the schema:

```bash
mysql -u root -p bbs < schema.sql
```

The `APP_KEY` is used to encrypt repository passphrases at rest (AES-256-GCM). Keep it safe — if lost, encrypted passphrases cannot be recovered.

**Default login:** `admin` / `admin` — change the password immediately after first login.

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

Database migrations run automatically on next page load via the Migrator. Check the release notes for any breaking changes.

---

## Troubleshooting

| Problem | Solution |
|---|---|
| Blank page | Set `APP_DEBUG=true` in `.env`, check PHP error log |
| Setup wizard won't start | Ensure `config/.env` does NOT exist and `config/` directory is writable by www-data |
| Database connection failed in wizard | Verify the MySQL user exists and has permissions. Check the host, credentials, and that MySQL is running. |
| "Config directory not writable" | Run `chown www-data:www-data /var/www/bbs/config && chmod 755 /var/www/bbs/config` |
| Database connection failed | Verify DB_HOST, DB_NAME, DB_USER, DB_PASS in `.env` |
| 404 on all routes | Enable Apache `mod_rewrite` or check Nginx `try_files` |
| Scheduler not running | Check `crontab -l`, verify path to `scheduler.php` |
| Agents can't connect (HTTPS) | Ensure SSL is configured and port 443 is open |
| Agents can't connect (SSH) | Ensure `sshd` is running and port 22 is open |
| SSH provisioning fails | Check `bbs-ssh-helper` is at `/usr/local/bin/`, sudoers is configured, web user matches |
| Borg not found | Install borg on the server: `apt install borgbackup` |
| Prune not running | Prune runs server-side in the scheduler — check cron and server borg install |
| Memcached not working | App works without it (graceful fallback), install `php-memcached` to enable |

# Borg Backup Server — Installation Guide

## Recommended Setup

| Component | Requirement |
|-----------|-------------|
| **Operating System** | Ubuntu 22.04 Server or higher |
| **RAM** | 4 GB recommended, 8 GB for larger deployments |
| **Storage** | For large deployments with many agents and millions of files, see [MySQL Storage Requirements](MySQL-Storage-Requirements.md) |
| **Storage Partition** | If using a dedicated storage device, mount it at `/var/bbs/home` for best results. This is where all borg repositories are stored. |

---

## Quick Install (Recommended)

Run the automated installer on a fresh Ubuntu 22.04+ server:

```bash
curl -sO https://raw.githubusercontent.com/marcpope/borgbackupserver/main/bin/bbs-install
sudo bash bbs-install --hostname backups.example.com
```

> Replace `backups.example.com` with your server's domain name or IP address. If you omit `--hostname`, the installer will prompt you for it.

For LAN installs without SSL:

```bash
sudo bash bbs-install --hostname 192.168.1.100 --no-ssl
```

The script installs all dependencies, configures Apache, sets up the database, and opens the setup wizard in your browser.

---

## Manual Installation

Complete step-by-step guide to install BBS on Ubuntu 22.04+ with Apache. Follow every step in order.

---

## Requirements

- **OS:** Ubuntu 22.04 or newer
- **A domain name** pointed at your server (e.g., `backups.example.com`)
- **Root access** to the server
- **MySQL storage:** BBS catalogs every backed-up file into MySQL for fast browsing and restore without locking borg repositories. Large deployments need adequate MySQL disk space and memory — see [MySQL Storage Requirements](MySQL-Storage-Requirements.md) for sizing estimates.

### Recommended: Set Server Timezone to UTC

BBS stores all timestamps in UTC internally and converts to each user's timezone for display. Setting your server to UTC simplifies debugging and log correlation:

```bash
timedatectl set-timezone UTC
```

This is optional — the application handles timezone conversion regardless of the server's local time.

---

## Step 1: Install System Packages

```bash
apt update
apt install -y apache2 libapache2-mod-php \
    php php-mysql php-mbstring php-xml php-curl php-memcached \
    mysql-server borgbackup composer git openssh-server \
    memcached certbot python3-certbot-apache
```

---

## Step 2: Create MySQL Database and User

```bash
sudo mysql -u root <<'SQL'
CREATE DATABASE bbs CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bbs'@'localhost' IDENTIFIED BY 'CHANGE_THIS_PASSWORD';
GRANT ALL PRIVILEGES ON bbs.* TO 'bbs'@'localhost';
FLUSH PRIVILEGES;
SQL
```

Replace `CHANGE_THIS_PASSWORD` with a strong password. You'll enter this in the setup wizard later.

---

## Step 3: Download BBS

```bash
cd /var/www
git clone https://github.com/marcpope/borgbackupserver.git bbs
cd bbs
git checkout $(git tag --sort=-v:refname | grep -E '^v[0-9]' | head -1)
composer install --no-dev
```

---

## Step 4: Set File Permissions

```bash
chown -R www-data:www-data /var/www/bbs
chmod 755 /var/www/bbs/config
```

---

## Step 5: Create Backup Storage Directory

The default storage path is `/var/bbs/home`. Each client gets a subdirectory here automatically.

```bash
mkdir -p /var/bbs/home
chown www-data:www-data /var/bbs/home
```

> **Large storage volumes:** If you have a dedicated disk or partition for backups, mount it at `/var/bbs/home` before running the installer. For example:
>
> ```bash
> mkfs.ext4 /dev/sdb1
> echo '/dev/sdb1 /var/bbs/home ext4 defaults 0 2' >> /etc/fstab
> mkdir -p /var/bbs/home
> mount /var/bbs/home
> chown www-data:www-data /var/bbs/home
> ```
>
> This keeps the default storage path while using your large volume for actual data.

---

## Step 6: Install the SSH Helper

Agents back up over SSH using `borg serve`. BBS creates restricted Unix users for each client via a helper script:

```bash
cp /var/www/bbs/bin/bbs-ssh-helper /usr/local/bin/bbs-ssh-helper
chmod 755 /usr/local/bin/bbs-ssh-helper
```

Allow Apache to run it without a password:

```bash
echo "www-data ALL=(root) NOPASSWD: /usr/local/bin/bbs-ssh-helper, /var/www/bbs/bin/bbs-update" > /etc/sudoers.d/bbs-ssh-helper
chmod 440 /etc/sudoers.d/bbs-ssh-helper
```

Allow the scheduler to run borg prune/compact as client users:

```bash
echo "www-data ALL=(bbs-*) NOPASSWD: /usr/bin/borg, /usr/bin/env" > /etc/sudoers.d/bbs-borg
chmod 440 /etc/sudoers.d/bbs-borg
```

Create the borg cache directory:

```bash
mkdir -p /var/bbs/cache
chown www-data:www-data /var/bbs/cache
```

Verify it works:

```bash
sudo -u www-data sudo /usr/local/bin/bbs-ssh-helper
# Should print: Usage: bbs-ssh-helper {create-user|create-repo-dir|borg-extract|fix-repo-perms|delete-user} [args...]
```

---

## Step 7: Configure Apache

Enable required modules:

```bash
a2enmod rewrite ssl
```

Create `/etc/apache2/sites-available/bbs.conf`:

```apache
<VirtualHost *:80>
    ServerName backups.example.com
    RewriteEngine On
    RewriteRule ^(.*)$ https://%{HTTP_HOST}$1 [R=301,L]
</VirtualHost>

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

Enable the site and disable the default:

```bash
a2dissite 000-default
a2ensite bbs
```

> **Important:** `AllowOverride All` is required. The included `public/.htaccess` passes the `Authorization` header through to PHP. Without it, all agent API requests will fail with `401 Missing authorization token`.

---

## Step 8: Configure SSL Certificate

**SSL is required.** Agents send API keys over HTTPS.

Get a certificate from Let's Encrypt (the Apache plugin was installed in Step 1, but if you see "plugin does not appear to be installed", run `apt install -y python3-certbot-apache` first):

```bash
certbot --apache -d backups.example.com
```

This will:
- Obtain the certificate
- Configure Apache SSL automatically
- Set up auto-renewal via systemd timer

Restart Apache:

```bash
systemctl restart apache2
```

Verify auto-renewal is active:

```bash
systemctl status certbot.timer
certbot renew --dry-run
```

> **Note:** If certbot modified your vhost files, verify that `DocumentRoot` still points to `/var/www/bbs/public` and `AllowOverride All` is still set. Run `cat /etc/apache2/sites-enabled/bbs*.conf` to check.

---

## Step 9: Run the Setup Wizard

Open your browser and go to `https://backups.example.com`. Since no `.env` file exists yet, the setup wizard starts automatically.

The wizard walks you through:

1. **System Check** — verifies PHP version and required extensions
2. **Database** — enter the MySQL host (`localhost`), database name (`bbs`), user (`bbs`), and password from Step 2
3. **Admin Account** — create your login (username, email, password)
4. **Storage & Server** — enter the storage path from Step 5 (`/var/bbs/home`), a label (e.g., "Primary"), and the server hostname (`backups.example.com`)
5. **Review & Install** — generates the encryption key, creates tables, writes `.env`

After completing the wizard, you'll be redirected to the login page.

---

## Step 10: Set Up the Scheduler (Cron)

The scheduler checks for due backups, processes the job queue, runs server-side prune, and monitors agent health. It must run every minute:

```bash
crontab -e
```

Add this line:

```
* * * * * /usr/bin/php /var/www/bbs/scheduler.php >> /var/log/bbs-scheduler.log 2>&1
```

Verify it's running after a minute:

```bash
tail -f /var/log/bbs-scheduler.log
```

---

## Step 11: Add Your First Client

1. Log in to the BBS web UI
2. Go to **Clients** → **Add Client**
3. Give it a name (e.g., `webserver-01`)
4. Copy the install command from the **Install Agent** tab
5. SSH into the remote server and paste the command:

```bash
curl -s https://backups.example.com/get-agent | sudo bash -s -- \
    --server https://backups.example.com --key YOUR_API_KEY
```

6. The agent will register, download its SSH key, and start polling
7. Back in BBS: go to the **Repos** tab and create a repository
8. Go to the **Schedules** tab and create a backup plan

Verify the agent is online:

```bash
# On the remote server:
systemctl status bbs-agent
journalctl -u bbs-agent -f
```

The agent supports Ubuntu, Debian, CentOS, RHEL, Rocky, AlmaLinux, Fedora, Arch, openSUSE, and macOS. It will auto-install borg on the remote server if not already present.

---

## Troubleshooting

| Problem | Solution |
|---|---|
| **Blank page** | Set `APP_DEBUG=true` in `config/.env`, check `tail /var/log/apache2/error.log` |
| **Setup wizard won't start** | Ensure `config/.env` does NOT exist and `config/` is writable: `chown www-data:www-data /var/www/bbs/config` |
| **Database connection failed** | Verify MySQL is running (`systemctl status mysql`), check credentials |
| **404 on all routes** | Run `a2enmod rewrite && systemctl restart apache2` |
| **Agent 401 "Missing authorization token"** | Ensure `AllowOverride All` is set in your Apache vhost |
| **Agent won't connect (HTTPS)** | Check SSL cert is valid (`certbot certificates`), port 443 is open |
| **Agent won't connect (SSH)** | Check `sshd` is running, port 22 is open, SSH key was provisioned |
| **SSH provisioning failed** | Verify: `ls /usr/local/bin/bbs-ssh-helper`, `cat /etc/sudoers.d/bbs-ssh-helper` |
| **Scheduler not running** | Check `crontab -l`, verify path, check `tail /var/log/bbs-scheduler.log` |
| **Borg not found** | Run `apt install borgbackup` (server needs borg for prune/restore/download) |
| **SSL certificate expired** | Run `certbot renew`, check `systemctl status certbot.timer` |
| **Permission denied errors** | Run `chown -R www-data:www-data /var/www/bbs` |
| **Download/extract permission denied** | Borg cache dir conflict — run `sudo /var/www/bbs/bin/bbs-update` to recreate `/var/bbs/cache/` dirs |
| **SSH helper out of date** | Re-copy after upgrade: `cp bin/bbs-ssh-helper /usr/local/bin/bbs-ssh-helper` |

---

## Upgrading

```bash
sudo /var/www/bbs/bin/bbs-update
```

This fetches the latest release tag, installs dependencies, fixes permissions, updates the SSH helper, and runs database migrations.

To upgrade to a specific version:

```bash
sudo /var/www/bbs/bin/bbs-update /var/www/bbs v0.8.1-beta
```

If you prefer to update manually:

```bash
cd /var/www/bbs
git fetch --tags --force
git checkout $(git tag --sort=-v:refname | grep -E '^v[0-9]' | head -1)
composer install --no-dev
chown -R www-data:www-data /var/www/bbs
cp bin/bbs-ssh-helper /usr/local/bin/bbs-ssh-helper
sudo -u www-data php migrate.php
```

---

## Manual Setup (Without Wizard)

If you prefer to skip the wizard and configure manually:

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
APP_KEY=
```

Generate the encryption key and append it:

```bash
php -r "echo 'APP_KEY=' . bin2hex(random_bytes(32)) . PHP_EOL;" >> config/.env
```

Import the database schema:

```bash
mysql -u bbs -p bbs < schema.sql
```

The `APP_KEY` encrypts repository passphrases at rest (AES-256-GCM). **Back it up.** If lost, encrypted passphrases cannot be recovered.

**Default login:** `admin` / `admin` — change the password immediately.

---

## Development Server

For local development without Apache:

```bash
cd /var/www/bbs/public
php -S localhost:8080
```

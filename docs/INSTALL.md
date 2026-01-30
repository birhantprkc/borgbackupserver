# Borg Backup Server — Installation Guide

Complete step-by-step guide to install BBS on a fresh Linux server. Follow every step in order.

---

## Requirements

- **OS:** Ubuntu 22.04+ or Debian 12+ (RHEL 9+ / Rocky 9+ also supported — see notes)
- **PHP:** 8.1 or newer
- **MySQL:** 8.0+ or MariaDB 10.6+
- **A domain name** pointed at your server (e.g., `backups.example.com`)
- **Root access** to the server

---

## Step 1: Install System Packages

### Ubuntu / Debian

```bash
apt update
apt install -y apache2 libapache2-mod-php \
    php php-mysql php-mbstring php-xml php-curl php-memcached \
    mysql-server borgbackup composer git openssh-server \
    memcached certbot python3-certbot-apache
```

### RHEL 9 / Rocky 9 / AlmaLinux 9

```bash
dnf install -y epel-release
dnf install -y httpd php php-mysqlnd php-mbstring php-xml php-json php-pecl-memcached \
    mysql-server borgbackup openssh-server composer git \
    memcached certbot python3-certbot-apache

systemctl enable --now mysqld httpd sshd memcached
```

> **Note:** On RHEL/Rocky, the web server user is `apache` instead of `www-data`. Every command below that references `www-data` should use `apache` instead. This is called out at each step.

---

## Step 2: Create MySQL Database and User

```bash
mysql -u root <<'SQL'
CREATE DATABASE bbs CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bbs'@'localhost' IDENTIFIED BY 'CHANGE_THIS_PASSWORD';
GRANT ALL PRIVILEGES ON bbs.* TO 'bbs'@'localhost';
FLUSH PRIVILEGES;
SQL
```

Replace `CHANGE_THIS_PASSWORD` with a strong password. You'll enter this in the setup wizard later.

> **MySQL 8 on Ubuntu:** If `mysql -u root` fails with access denied, try `sudo mysql -u root` — Ubuntu's default MySQL uses socket auth for root.

---

## Step 3: Download BBS

```bash
cd /var/www
git clone https://github.com/marcpope/borgbackupserver.git bbs
cd bbs
composer install --no-dev
```

---

## Step 4: Set File Permissions

The web server user needs to own the application files:

```bash
# Ubuntu/Debian:
chown -R www-data:www-data /var/www/bbs

# RHEL/Rocky/AlmaLinux:
# chown -R apache:apache /var/www/bbs
```

The `config/` directory must be writable (the setup wizard creates `.env` here):

```bash
chmod 755 /var/www/bbs/config
```

---

## Step 5: Create Backup Storage Directory

Choose where borg repositories will be stored. This should be on a partition with plenty of space:

```bash
mkdir -p /var/bbs/home

# Ubuntu/Debian:
chown www-data:www-data /var/bbs/home

# RHEL/Rocky/AlmaLinux:
# chown apache:apache /var/bbs/home
```

You'll enter this path in the setup wizard. Each client gets a subdirectory here automatically.

---

## Step 6: Install the SSH Helper

Agents back up over SSH using `borg serve`. BBS creates restricted Unix users for each client via a helper script:

```bash
cp /var/www/bbs/bin/bbs-ssh-helper /usr/local/bin/bbs-ssh-helper
chmod 755 /usr/local/bin/bbs-ssh-helper
```

Allow the web server user to run it without a password:

```bash
# Ubuntu/Debian:
echo "www-data ALL=(root) NOPASSWD: /usr/local/bin/bbs-ssh-helper" > /etc/sudoers.d/bbs-ssh-helper

# RHEL/Rocky/AlmaLinux:
# echo "apache ALL=(root) NOPASSWD: /usr/local/bin/bbs-ssh-helper" > /etc/sudoers.d/bbs-ssh-helper

chmod 440 /etc/sudoers.d/bbs-ssh-helper
```

Verify it works:

```bash
sudo -u www-data sudo /usr/local/bin/bbs-ssh-helper
# Should print: Usage: bbs-ssh-helper {create-user|delete-user} [args...]
```

---

## Step 7: Configure SSL Certificate

**SSL is required.** Agents send API keys over HTTPS.

Get a certificate from Let's Encrypt using the Apache plugin (recommended — handles renewal automatically with no downtime):

```bash
certbot --apache -d backups.example.com
```

This will:
- Obtain the certificate
- Configure Apache SSL automatically
- Set up auto-renewal via systemd timer

Verify auto-renewal is active:

```bash
systemctl status certbot.timer
certbot renew --dry-run
```

> **Using Nginx instead?** Install `python3-certbot-nginx` and run `certbot --nginx -d backups.example.com`.

---

## Step 8: Configure Apache

Enable required Apache modules:

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

Enable the site and restart:

```bash
a2dissite 000-default
a2ensite bbs
systemctl restart apache2
```

> **Important:** `AllowOverride All` is required. The included `public/.htaccess` contains a rewrite rule that passes the `Authorization` header through to PHP. Without it, all agent API requests will fail with `401 Missing authorization token`.

> **Note:** If certbot already created an SSL vhost for you in step 7, you can edit that file instead of creating a new one. Just make sure `DocumentRoot` points to `/var/www/bbs/public` and the `<Directory>` block has `AllowOverride All`.

<details>
<summary><strong>Nginx Configuration (click to expand)</strong></summary>

```nginx
server {
    listen 80;
    server_name backups.example.com;
    return 301 https://$host$request_uri;
}

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
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\. {
        deny all;
    }
}
```

```bash
systemctl restart nginx php8.1-fpm
```

</details>

---

## Step 9: Run the Setup Wizard

Open your browser and go to `https://backups.example.com`. Since no `.env` file exists yet, the setup wizard starts automatically.

The wizard walks you through:

1. **System Check** — verifies PHP version and required extensions
2. **Database** — enter the MySQL host, database name, user, and password from Step 2
3. **Admin Account** — create your login (username, email, password)
4. **Storage & Server** — enter the storage path from Step 5 (e.g., `/var/bbs/home`), a label (e.g., "Primary"), and the server hostname (e.g., `backups.example.com`)
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
* * * * * php /var/www/bbs/scheduler.php >> /var/log/bbs-scheduler.log 2>&1
```

> **RHEL/Rocky:** If crontab runs as root, add `-u www-data` or create `/etc/cron.d/bbs` instead:
> ```
> * * * * * www-data php /var/www/bbs/scheduler.php >> /var/log/bbs-scheduler.log 2>&1
> ```

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
curl -s "https://backups.example.com/api/agent/download?file=install.sh" | sudo bash -s -- \
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

---

## Troubleshooting

| Problem | Solution |
|---|---|
| **Blank page** | Set `APP_DEBUG=true` in `config/.env`, check `tail /var/log/apache2/error.log` |
| **Setup wizard won't start** | Ensure `config/.env` does NOT exist and `config/` is writable by the web user |
| **Database connection failed** | Verify MySQL is running (`systemctl status mysql`), check credentials |
| **404 on all routes** | Enable `mod_rewrite` (`a2enmod rewrite && systemctl restart apache2`) |
| **Agent 401 "Missing authorization token"** | Ensure `AllowOverride All` is set in Apache config so `.htaccess` rules work |
| **Agent won't connect (HTTPS)** | Check SSL cert is valid, port 443 is open in firewall |
| **Agent won't connect (SSH)** | Check `sshd` is running, port 22 is open, SSH key was provisioned |
| **SSH provisioning failed** | Check `bbs-ssh-helper` is at `/usr/local/bin/`, sudoers entry matches your web user |
| **Scheduler not running** | Check `crontab -l`, verify path in cron entry, check log file |
| **Borg not found** | Install on the server: `apt install borgbackup` (server needs borg for prune/restore) |
| **SSL certificate expired** | Run `certbot renew` and check `systemctl status certbot.timer` |
| **Permission denied errors** | Re-run `chown -R www-data:www-data /var/www/bbs` |

---

## Upgrading

```bash
cd /var/www/bbs
git pull
composer install --no-dev
chown -R www-data:www-data /var/www/bbs
```

Database migrations run automatically on next page load.

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

For local development without Apache/Nginx:

```bash
cd /var/www/bbs/public
php -S localhost:8080
```

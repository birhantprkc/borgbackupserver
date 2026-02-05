#!/bin/bash
set -e

echo "=== BBS Container Starting ==="

# --- SSH host key persistence ---
# Persist host keys on the data volume so agents don't see "host key changed"
# errors after a container rebuild
SSH_KEY_DIR="/var/bbs/.ssh-host-keys"
if [ -d "$SSH_KEY_DIR" ] && [ "$(ls -A $SSH_KEY_DIR 2>/dev/null)" ]; then
    echo "Restoring SSH host keys from volume..."
    cp "$SSH_KEY_DIR"/ssh_host_* /etc/ssh/ 2>/dev/null || true
    chmod 600 /etc/ssh/ssh_host_*_key 2>/dev/null || true
    chmod 644 /etc/ssh/ssh_host_*_key.pub 2>/dev/null || true
else
    echo "Saving SSH host keys to volume..."
    mkdir -p "$SSH_KEY_DIR"
    cp /etc/ssh/ssh_host_* "$SSH_KEY_DIR/" 2>/dev/null || true
fi

# Start SSH server
echo "Starting SSH server..."
/usr/sbin/sshd

# --- MariaDB ---
# Store database files on the persistent volume
MYSQL_DATADIR="/var/bbs/mysql"
mkdir -p "$MYSQL_DATADIR"
chown mysql:mysql "$MYSQL_DATADIR"

echo "Starting MariaDB..."
if [ ! -d "$MYSQL_DATADIR/mysql" ]; then
    echo "Initializing MariaDB data directory..."
    mysql_install_db --user=mysql --datadir="$MYSQL_DATADIR" > /dev/null 2>&1
fi
mysqld_safe --datadir="$MYSQL_DATADIR" &
sleep 3

# Wait for MySQL to be ready
echo "Waiting for MariaDB..."
for i in {1..30}; do
    if mysqladmin ping -h localhost --silent 2>/dev/null; then
        break
    fi
    sleep 1
done

# Create database and user if needed
if ! mysql -e "SELECT 1 FROM mysql.user WHERE user='bbs'" 2>/dev/null | grep -q 1; then
    echo "Creating BBS database and user..."
    mysql -e "CREATE DATABASE IF NOT EXISTS bbs CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql -e "CREATE USER IF NOT EXISTS 'bbs'@'localhost' IDENTIFIED BY 'bbs';"
    mysql -e "CREATE USER IF NOT EXISTS 'bbs'@'127.0.0.1' IDENTIFIED BY 'bbs';"
    mysql -e "GRANT ALL PRIVILEGES ON bbs.* TO 'bbs'@'localhost';"
    mysql -e "GRANT ALL PRIVILEGES ON bbs.* TO 'bbs'@'127.0.0.1';"
    mysql -e "FLUSH PRIVILEGES;"
fi

# --- Storage directories ---
mkdir -p /var/bbs/home
mkdir -p /var/bbs/cache
mkdir -p /var/bbs/backups

# Set permissions (precise ordering to avoid overwriting mysql ownership)
chown -R www-data:www-data /var/bbs/home /var/bbs/cache /var/bbs/backups
chown -R mysql:mysql "$MYSQL_DATADIR"

# --- Application configuration ---
# Extract hostname from APP_URL (strip protocol and trailing path)
SERVER_HOST="$(echo "${APP_URL:-http://localhost}" | sed -E 's|https?://||' | sed 's|/.*||')"

# Generate APP_KEY if not provided via env
if [ -z "$APP_KEY" ]; then
    APP_KEY="$(openssl rand -hex 32)"
fi

# Create .env if it doesn't exist
if [ ! -f "/var/www/bbs/config/.env" ]; then
    echo "Creating .env configuration..."
    mkdir -p /var/www/bbs/config
    cat > /var/www/bbs/config/.env << EOF
DB_HOST=127.0.0.1
DB_NAME=bbs
DB_USER=bbs
DB_PASS=bbs
APP_URL=${APP_URL:-http://localhost}
APP_KEY=$APP_KEY
EOF
    chown www-data:www-data /var/www/bbs/config/.env
    chmod 600 /var/www/bbs/config/.env
fi

# Ensure APP_KEY exists in .env (for containers upgraded from older versions)
if ! grep -q '^APP_KEY=' /var/www/bbs/config/.env 2>/dev/null; then
    echo "Adding APP_KEY to existing .env..."
    echo "APP_KEY=$APP_KEY" >> /var/www/bbs/config/.env
fi

# --- Database setup ---
FRESH_INSTALL=0
if ! mysql -u bbs -pbbs bbs -e "SELECT 1 FROM settings LIMIT 1" 2>/dev/null; then
    FRESH_INSTALL=1
fi

# Import schema on fresh install
if [ -f "/var/www/bbs/schema.sql" ]; then
    if [ "$FRESH_INSTALL" -eq 1 ]; then
        echo "Importing database schema..."
        mysql -u bbs -pbbs bbs < /var/www/bbs/schema.sql
    fi
fi

# Set essential settings
echo "Configuring server settings..."
mysql -u bbs -pbbs bbs -e "INSERT INTO settings (\`key\`, \`value\`) VALUES ('storage_path', '/var/bbs/home') ON DUPLICATE KEY UPDATE \`value\` = IF(\`value\` = '' OR \`value\` IS NULL, '/var/bbs/home', \`value\`);"
mysql -u bbs -pbbs bbs -e "INSERT INTO settings (\`key\`, \`value\`) VALUES ('server_host', '$SERVER_HOST') ON DUPLICATE KEY UPDATE \`value\` = IF(\`value\` = '' OR \`value\` IS NULL, '$SERVER_HOST', \`value\`);"

# Set SSH port (must match the host-side port mapping for borg agent connections)
SSH_PORT="${SSH_PORT:-22}"
mysql -u bbs -pbbs bbs -e "INSERT INTO settings (\`key\`, \`value\`) VALUES ('ssh_port', '$SSH_PORT') ON DUPLICATE KEY UPDATE \`value\` = '$SSH_PORT';"

# Set admin password on fresh install
if [ "$FRESH_INSTALL" -eq 1 ]; then
    if [ -z "$ADMIN_PASS" ]; then
        ADMIN_PASS="$(openssl rand -base64 12 | tr -d '/+=' | head -c 16)"
        echo ""
        echo "========================================"
        echo "  ADMIN CREDENTIALS (SAVE THESE!)"
        echo "========================================"
        echo "  URL:      ${APP_URL:-http://localhost}"
        echo "  Username: admin"
        echo "  Password: $ADMIN_PASS"
        echo "========================================"
        echo ""
    fi

    # Generate bcrypt hash and update admin password
    export ADMIN_PASS
    ADMIN_HASH=$(php -r "echo password_hash(\$_SERVER['ADMIN_PASS'], PASSWORD_BCRYPT, ['cost' => 12]);")
    mysql -u bbs -pbbs bbs -e "UPDATE users SET password_hash = '$ADMIN_HASH' WHERE username = 'admin';"
fi

# Run pending migrations
if [ -d "/var/www/bbs/migrations" ]; then
    echo "Running migrations..."
    for migration in /var/www/bbs/migrations/*.sql; do
        if [ -f "$migration" ]; then
            mysql -u bbs -pbbs bbs < "$migration" 2>/dev/null || true
        fi
    done
fi

# Sync borg versions from GitHub on fresh install or if table is empty
BORG_COUNT=$(mysql -u bbs -pbbs bbs -N -e "SELECT COUNT(*) FROM borg_versions" 2>/dev/null || echo "0")
if [ "$BORG_COUNT" -eq 0 ]; then
    echo "Syncing borg versions from GitHub..."
    cd /var/www/bbs && php -r "
        require 'vendor/autoload.php';
        \$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/config');
        \$dotenv->load();
        \$svc = new BBS\Services\BorgVersionService();
        \$result = \$svc->syncVersionsFromGitHub();
        echo isset(\$result['added']) ? \"{$result['added']} versions synced\" : (\$result['error'] ?? 'unknown error');
        echo PHP_EOL;
    " 2>/dev/null || echo "Warning: Could not sync borg versions"
fi

# --- Recreate SSH users from volume (needed after container restart) ---
# User UIDs are stored in each home dir to ensure consistent ownership across restarts
echo "Recreating SSH users from volume..."
for USER_HOME in /var/bbs/home/bbs-*; do
    [ -d "$USER_HOME" ] || continue
    SSH_USER=$(basename "$USER_HOME")

    # Skip if user already exists in container
    if id "$SSH_USER" &>/dev/null; then
        continue
    fi

    # Get UID from stored file, or from directory ownership
    if [ -f "$USER_HOME/.uid" ]; then
        STORED_UID=$(cat "$USER_HOME/.uid")
    else
        STORED_UID=$(stat -c %u "$USER_HOME" 2>/dev/null || stat -f %u "$USER_HOME" 2>/dev/null)
        if [ "$STORED_UID" = "0" ] || [ -z "$STORED_UID" ]; then
            STORED_UID=$(awk -F: '($3>=1000)&&($3<60000){print $3}' /etc/passwd | sort -n | tail -1)
            STORED_UID=$((STORED_UID + 1))
            [ "$STORED_UID" -lt 1000 ] && STORED_UID=1000
        fi
        echo "$STORED_UID" > "$USER_HOME/.uid"
    fi

    # Create user with preserved UID
    groupadd -g "$STORED_UID" "$SSH_USER" 2>/dev/null || true
    useradd -u "$STORED_UID" -g "$STORED_UID" -d "$USER_HOME" -s /bin/bash "$SSH_USER" 2>/dev/null || true

    # Fix ownership
    chown -R "$SSH_USER:$SSH_USER" "$USER_HOME"

    echo "  Restored user: $SSH_USER (uid=$STORED_UID)"
done

# --- Cron ---
echo "Setting up scheduler cron..."
touch /var/log/bbs-scheduler.log
chown www-data:www-data /var/log/bbs-scheduler.log
cat > /etc/cron.d/bbs-scheduler << 'CRON'
* * * * * www-data cd /var/www/bbs && /usr/local/bin/php scheduler.php >> /var/log/bbs-scheduler.log 2>&1
# Save UIDs for any new bbs-* users (needed for Docker restarts)
*/5 * * * * root for d in /var/bbs/home/bbs-*; do [ -d "$d" ] && [ ! -f "$d/.uid" ] && stat -c \%u "$d" > "$d/.uid" 2>/dev/null; done
CRON
chmod 644 /etc/cron.d/bbs-scheduler
cron

echo "=== BBS Container Ready ==="

exec apache2-foreground

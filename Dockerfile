FROM php:8.1-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libfuse-dev \
    fuse \
    zip \
    unzip \
    cron \
    sudo \
    mariadb-server \
    mariadb-client \
    borgbackup \
    rclone \
    openssh-client \
    openssh-server \
    python3-pip \
    gnupg \
    && rm -rf /var/lib/apt/lists/*

# Install ClickHouse (catalog engine)
RUN curl -fsSL 'https://packages.clickhouse.com/rpm/lts/repodata/repomd.xml.key' | \
        gpg --dearmor -o /usr/share/keyrings/clickhouse-keyring.gpg && \
    echo "deb [signed-by=/usr/share/keyrings/clickhouse-keyring.gpg] https://packages.clickhouse.com/deb stable main" \
        > /etc/apt/sources.list.d/clickhouse.list && \
    apt-get update && \
    DEBIAN_FRONTEND=noninteractive apt-get install -y clickhouse-server clickhouse-client && \
    rm -rf /var/lib/apt/lists/*

# Install Apprise (notification tool supporting 100+ services)
RUN pip3 install --break-system-packages --no-cache-dir apprise

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql mbstring

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Enable Apache modules
RUN a2enmod rewrite

# Configure Apache
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Apache vhost configuration
RUN echo '<VirtualHost *:80>\n\
    DocumentRoot /var/www/bbs/public\n\
    <Directory /var/www/bbs/public>\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    ErrorLog ${APACHE_LOG_DIR}/error.log\n\
    CustomLog ${APACHE_LOG_DIR}/access.log combined\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

# Configure SSH - disable password auth, only allow key-based
RUN sed -i 's/#PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config \
    && sed -i 's/#PubkeyAuthentication yes/PubkeyAuthentication yes/' /etc/ssh/sshd_config \
    && echo "PermitRootLogin no" >> /etc/ssh/sshd_config

# Create directories
RUN mkdir -p /var/www/bbs /var/bbs/home /var/bbs/backups /var/bbs/cache /run/mysqld /run/sshd \
    && chown -R www-data:www-data /var/www/bbs /var/bbs \
    && chown mysql:mysql /run/mysqld

# Configure scoped sudoers for www-data
RUN echo "www-data ALL=(root) NOPASSWD: /usr/local/bin/bbs-ssh-helper, /var/www/bbs/bin/bbs-update" > /etc/sudoers.d/bbs-ssh-helper \
    && echo "www-data ALL=(bbs-*) NOPASSWD: /usr/bin/borg, /usr/local/bin/borg, /usr/bin/rclone, /usr/bin/env" > /etc/sudoers.d/bbs-borg \
    && chmod 440 /etc/sudoers.d/bbs-ssh-helper /etc/sudoers.d/bbs-borg

# Copy entrypoint script
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80 22

ENTRYPOINT ["/entrypoint.sh"]

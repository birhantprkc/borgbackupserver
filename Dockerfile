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
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql mbstring

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

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy application code and install dependencies
COPY . /var/www/bbs/
RUN cd /var/www/bbs && composer install --no-dev --optimize-autoloader --no-interaction

# Install bbs-ssh-helper
RUN cp /var/www/bbs/bin/bbs-ssh-helper /usr/local/bin/bbs-ssh-helper \
    && chmod 755 /usr/local/bin/bbs-ssh-helper

# Set ownership
RUN chown -R www-data:www-data /var/www/bbs

# Configure scoped sudoers for www-data
RUN echo "www-data ALL=(root) NOPASSWD: /usr/local/bin/bbs-ssh-helper" > /etc/sudoers.d/bbs-ssh-helper \
    && echo "www-data ALL=(bbs-*) NOPASSWD: /usr/bin/borg, /usr/local/bin/borg, /usr/bin/rclone, /usr/bin/env" > /etc/sudoers.d/bbs-borg \
    && chmod 440 /etc/sudoers.d/bbs-ssh-helper /etc/sudoers.d/bbs-borg

# Copy entrypoint script
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80 22

ENTRYPOINT ["/entrypoint.sh"]

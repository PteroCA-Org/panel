FROM php:8.2.35-fpm

# Install required PHP extensions
RUN apt-get update && apt-get install -y \
    git \
    zip \
    unzip \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    default-mysql-client \
    nginx \
    supervisor \
    cron \
    ca-certificates \
    && docker-php-ext-install \
    pdo \
    pdo_mysql \
    zip \
    intl \
    mbstring \
    opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && update-ca-certificates

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Configure Nginx
COPY docker/nginx/default.conf /etc/nginx/sites-available/default
RUN ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# Configure Supervisor
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Configure PHP-FPM
COPY docker/php/php.ini /usr/local/etc/php/php.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

# Copy composer files first (better layer caching - only re-runs when dependencies change)
COPY composer.json composer.lock ./

# Install dependencies (without autoload optimization - src/ not yet available)
ARG APP_ENV=prod
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN if [ "$APP_ENV" = "dev" ]; then \
        composer install --no-interaction --no-scripts; \
    else \
        composer install --no-dev --no-interaction --no-scripts; \
    fi

# Copy application files
COPY . .

# Rebuild autoload with full classmap (now that src/ is available)
# Dev uses --optimize only (not authoritative) so bind-mounted new classes are found via PSR-4 fallback
RUN if [ "$APP_ENV" = "dev" ]; then \
        composer dump-autoload --optimize; \
    else \
        composer dump-autoload --optimize --no-dev --classmap-authoritative; \
    fi

# Setup PteroCA cron job
RUN echo "* * * * * www-data php /app/bin/console app:cron-job-schedule >> /dev/null 2>&1" > /etc/cron.d/pteroca-cron \
    && chmod 0644 /etc/cron.d/pteroca-cron \
    && crontab -u www-data /etc/cron.d/pteroca-cron

# Set permissions on directories that need write access
RUN mkdir -p var/log var/cache public/uploads plugins themes \
    && chown -R www-data:www-data var public/uploads plugins themes \
    && chmod -R 775 var public/uploads plugins themes

# Expose port
EXPOSE 80

# Default command
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

# syntax=docker/dockerfile:1

# =============================================================
#  PHP 8.4-FPM image for the SccIT backend.
#  One image, three runtime roles (app / queue / scheduler).
#  Multi-stage: base -> development (bind-mount) / production (baked).
# =============================================================

# ---------- Base: extensions + Composer ----------------------
FROM php:8.4-fpm-bookworm AS base

# System libs required to build the PHP extensions below.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libicu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libfcgi-bin \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        bcmath \
        intl \
        zip \
        gd \
        pcntl \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Composer (from the official image)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# App PHP + FPM pool configuration
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-sccit.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-www.conf

# FPM healthcheck helper (uses the pool's ping.path)
COPY docker/php/fpm-healthcheck.sh /usr/local/bin/fpm-healthcheck
RUN chmod +x /usr/local/bin/fpm-healthcheck

# Non-root user (UID/GID aligned to the host's typical 1000)
ARG UID=1000
ARG GID=1000
RUN groupadd -g ${GID} appuser \
    && useradd -u ${UID} -g appuser -m -s /bin/bash appuser \
    && chown -R appuser:appuser /var/www

WORKDIR /var/www/html

# ---------- Development: source is bind-mounted ---------------
FROM base AS development
USER appuser
EXPOSE 9000
HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=5 \
    CMD ["fpm-healthcheck"]
CMD ["php-fpm"]

# ---------- Production: bake source + deps (seam for later) ---
FROM base AS production
COPY --chown=appuser:appuser backend /var/www/html
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    && php artisan storage:link || true
USER appuser
EXPOSE 9000
HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=5 \
    CMD ["fpm-healthcheck"]
CMD ["php-fpm"]

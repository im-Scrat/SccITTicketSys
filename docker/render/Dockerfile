# syntax=docker/dockerfile:1

# =============================================================
#  SccIT — single-container image for the Render FREE-TIER PILOT.
#
#  Why one container (and not the Compose shape of docker/php + docker/nginx):
#  Render's free web services cannot receive private-network traffic, there
#  are no free background workers or cron jobs, the 750 free instance-hours
#  are shared by the whole workspace, and only one public port reaches the
#  service (render.com/docs/free, /docs/websocket). onrender.com is on the
#  Public Suffix List, so the SPA and the API must also share one origin for
#  Sanctum's cookies. nginx, php-fpm, Reverb, one database-queue worker and
#  the scheduler therefore run here under supervisord — each restarted on its
#  own. Data stays OUTSIDE: Postgres and object storage are external services,
#  because this container's filesystem is lost on every restart and spin-down.
#
#  The local production stack (compose.prod.yaml) is unchanged and does not
#  use this file. Build context: repository root.
#
#  Free instances have 512 MB and 0.1 CPU; the PHP settings below
#  (docker/render/php.render.ini, www.render.conf) are sized for that.
# =============================================================

# ---------- SPA build (same steps as docker/nginx/Dockerfile.prod) ----------
FROM node:22-alpine AS spa
WORKDIR /app
ENV PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

# ---------- Runtime: the extensions of docker/php/Dockerfile + nginx/supervisor
FROM php:8.4-fpm-bookworm AS runtime

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libicu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        nginx \
        supervisor \
        gettext-base \
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
    && rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/default

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHP: the same base and production settings as the Docker stack, then the
# free-tier sizing on top (loaded last, so it wins).
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-sccit.ini
COPY docker/php/php.prod.ini /usr/local/etc/php/conf.d/zzz-sccit-prod.ini
COPY docker/render/php.render.ini /usr/local/etc/php/conf.d/zzzz-sccit-render.ini
RUN rm -f /usr/local/etc/php-fpm.d/www.conf /usr/local/etc/php-fpm.d/zz-docker.conf
COPY docker/render/www.render.conf /usr/local/etc/php-fpm.d/www.conf

ARG UID=1000
ARG GID=1000
RUN groupadd -g ${GID} appuser \
    && useradd -u ${UID} -g appuser -m -s /bin/bash appuser

WORKDIR /var/www/html
COPY --chown=appuser:appuser backend /var/www/html
RUN runuser -u appuser -- composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# The SPA and the same security headers as the Docker stack's nginx.
COPY --from=spa /app/dist /var/www/spa
COPY docker/nginx/security-headers.prod.conf /etc/nginx/snippets/security-headers.conf
COPY docker/render/nginx.conf.template /etc/sccit/nginx.conf.template
COPY docker/render/supervisord.conf /etc/supervisor/conf.d/sccit.conf
COPY docker/render/entrypoint.sh /usr/local/bin/render-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/render-entrypoint /etc/sccit/nginx.conf.template /etc/supervisor/conf.d/sccit.conf \
    && chmod +x /usr/local/bin/render-entrypoint

# Render routes public traffic to $PORT (default 10000).
ENV PORT=10000
EXPOSE 10000

ENTRYPOINT ["/usr/local/bin/render-entrypoint"]
CMD ["supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]

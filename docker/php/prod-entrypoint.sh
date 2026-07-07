#!/bin/sh
# =============================================================
#  Production PHP-FPM entrypoint (app / queue / scheduler roles).
#
#  Builds Laravel's framework caches from the environment injected by
#  Compose (env_file), then hands off to the container's command
#  (php-fpm, queue:work, schedule:work).
#
#  Why at container start (not build time): config:cache bakes the *current*
#  environment into bootstrap/cache/config.php. Doing it here — after Compose
#  has injected the real runtime env — means the cache reflects production
#  values, not whatever happened to exist during `docker build`.
# =============================================================
set -e

cd /var/www/html

# Start from a clean slate (idempotent across container restarts), then rebuild
# the config / route / event caches. These are the production boot-time wins:
# config is parsed once (not per request), routes are pre-compiled, and the
# event->listener map is precomputed.
php artisan optimize:clear >/dev/null 2>&1 || true
php artisan config:cache
php artisan route:cache
php artisan event:cache

# Public storage symlink (idempotent; safe if it already exists).
php artisan storage:link >/dev/null 2>&1 || true

exec "$@"

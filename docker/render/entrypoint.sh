#!/bin/sh
# =============================================================
#  Render free-tier container entrypoint.
#
#  Render's free instances have no shell and no pre-deploy command
#  (render.com/docs/free), so the steps a deploy script would run happen here,
#  at container start, from the environment Render injects:
#
#    1  nginx.conf from its template, for Render's $PORT
#    2  Laravel config/route/event caches (as appuser, from the real env)
#    3  migrations — only when RUN_MIGRATIONS=1
#    4  first-install seeding — only when RUN_FIRST_INSTALL=1 AND the database
#       has no users at all. Seeding is never automatic: re-running it on a
#       live database is exactly what the seed-once guards exist to make
#       harmless, and this keeps it from being attempted in the first place.
#
#  Credentials are never printed: every artisan call's output is limited to
#  its own status lines.
# =============================================================
set -e

cd /var/www/html

: "${PORT:=10000}"
envsubst '${PORT}' < /etc/sccit/nginx.conf.template > /etc/nginx/nginx.conf

as_app() { runuser -u appuser -- "$@"; }

as_app php artisan optimize:clear >/dev/null 2>&1 || true
as_app php artisan config:cache
as_app php artisan route:cache
as_app php artisan event:cache

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    echo "render-entrypoint: running migrations"
    as_app php artisan migrate --force
fi

if [ "${RUN_FIRST_INSTALL:-0}" = "1" ]; then
    USERS="$(as_app php artisan tinker --execute='echo \App\Models\User::withTrashed()->count();' 2>/dev/null | tail -1 | tr -dc '0-9')"
    if [ "$USERS" = "0" ]; then
        echo "render-entrypoint: empty database — seeding reference data and the first administrator"
        as_app php artisan db:seed --force
    else
        echo "render-entrypoint: RUN_FIRST_INSTALL=1 ignored — the database already has ${USERS:-?} user(s). Remove the variable."
    fi
fi

exec "$@"

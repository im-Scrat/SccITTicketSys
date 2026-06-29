#!/usr/bin/env sh
# Drop all tables and re-run migrations (optionally --seed).
set -e
cd "$(dirname "$0")/.."
docker compose exec app php artisan migrate:fresh "$@"

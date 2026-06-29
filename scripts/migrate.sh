#!/usr/bin/env sh
# Run database migrations inside the app container.
set -e
cd "$(dirname "$0")/.."
docker compose exec app php artisan migrate "$@"

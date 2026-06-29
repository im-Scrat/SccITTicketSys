#!/usr/bin/env sh
# Back up the PostgreSQL database to backups/<db>-<timestamp>.sql.gz
set -e
cd "$(dirname "$0")/.."

DB="${POSTGRES_DB:-school_it_service_management}"
USER="${POSTGRES_USER:-postgres}"

mkdir -p backups
TS=$(date +%Y%m%d-%H%M%S)
OUT="backups/${DB}-${TS}.sql.gz"

docker compose exec -T postgres pg_dump -U "$USER" "$DB" | gzip > "$OUT"
echo "Backup written: $OUT"

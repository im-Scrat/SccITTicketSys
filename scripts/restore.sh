#!/usr/bin/env sh
# Restore a gzipped SQL dump into the PostgreSQL database.
# Usage: scripts/restore.sh backups/<file>.sql.gz
set -e
cd "$(dirname "$0")/.."

FILE="$1"
if [ -z "$FILE" ]; then
  echo "Usage: $0 <backup.sql.gz>"
  exit 1
fi
if [ ! -f "$FILE" ]; then
  echo "File not found: $FILE"
  exit 1
fi

DB="${POSTGRES_DB:-school_it_service_management}"
USER="${POSTGRES_USER:-postgres}"

gunzip -c "$FILE" | docker compose exec -T postgres psql -U "$USER" -d "$DB"
echo "Restored $FILE into $DB"

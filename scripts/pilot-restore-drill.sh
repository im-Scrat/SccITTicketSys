#!/usr/bin/env sh
# =============================================================
#  SccIT — restore drill for an encrypted Render-pilot backup.
#
#      scripts/pilot-restore-drill.sh <pilot-<run>.tar.age> <age-identity-file>
#
#  Decrypts a backup written by .github/workflows/backup-pilot.yml ON THIS
#  MACHINE (the private key never goes to GitHub), checks its SHA-256 sums,
#  restores the database into a disposable pgvector/pg17 container with no
#  network, and reports tables, migrations and row counts. Nothing is restored
#  anywhere real, and the scratch container and plaintext are removed on exit.
#
#  A real recovery uses the same dump against a NEW, empty database:
#      pg_restore --no-owner --no-privileges --dbname="<new database url>" database.dump
#  and copies files.tar.gz back into a new bucket.
# =============================================================
set -eu

ARCHIVE="${1:?usage: pilot-restore-drill.sh <backup.tar.age> <age-identity-file>}"
IDENTITY="${2:?usage: pilot-restore-drill.sh <backup.tar.age> <age-identity-file>}"
command -v age >/dev/null 2>&1 || { echo "age is required (https://age-encryption.org)" >&2; exit 1; }

WORK="$(mktemp -d)"
SCRATCH="sccit_pilot_drill_$$"
cleanup() { docker rm -f "$SCRATCH" >/dev/null 2>&1 || true; rm -rf "$WORK"; }
trap cleanup EXIT INT TERM

echo "== decrypt"
age -d -i "$IDENTITY" -o "$WORK/backup.tar" "$ARCHIVE"
tar -C "$WORK" -xf "$WORK/backup.tar"
( cd "$WORK" && sha256sum -c SHA256SUMS )

echo "== restore into a disposable database (no network)"
docker run -d --name "$SCRATCH" --network none -e POSTGRES_PASSWORD=drill -e POSTGRES_HOST_AUTH_METHOD=trust pgvector/pgvector:pg17 >/dev/null
for _ in $(seq 1 60); do docker exec "$SCRATCH" pg_isready -U postgres >/dev/null 2>&1 && break; sleep 1; done
docker exec "$SCRATCH" psql -U postgres -qc 'CREATE EXTENSION IF NOT EXISTS vector; CREATE EXTENSION IF NOT EXISTS citext; CREATE EXTENSION IF NOT EXISTS pg_trgm;' >/dev/null
docker cp "$WORK/database.dump" "$SCRATCH:/tmp/database.dump"
docker exec "$SCRATCH" pg_restore --no-owner --no-privileges --exit-on-error -U postgres -d postgres /tmp/database.dump

echo "== result"
docker exec "$SCRATCH" psql -U postgres -tAc "SELECT 'tables', count(*) FROM information_schema.tables WHERE table_schema = 'public'
  UNION ALL SELECT 'migrations', count(*) FROM migrations
  UNION ALL SELECT 'users', count(*) FROM users
  UNION ALL SELECT 'tickets', count(*) FROM tickets" | tr '|' ' '
echo "files archive: $(tar -tzf "$WORK/files.tar.gz" | grep -vc '/$') file(s)"
echo "RESTORE DRILL PASSED"

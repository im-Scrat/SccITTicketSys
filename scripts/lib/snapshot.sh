#!/usr/bin/env sh
# =============================================================
#  Shared helpers for production snapshots — sourced by backup.sh,
#  verify-backup.sh and restore.sh, after scripts/lib/stack.sh.
#
#  WHY THIS FILE EXISTS
#  A snapshot is only worth having if it can be shown to restore, and that
#  proof only counts if it runs the same command a real restore runs. Keeping
#  the integrity checks, the disposable database, the archive and restore
#  helpers in one place means verify-backup.sh and restore.sh cannot drift apart.
#
#  Snapshot layout (docs/OPERATIONS.md §2):
#      backups/prod/<UTC-timestamp>[-label]/
#      ├── database.sql.gz   pg_dump --clean --if-exists, gzipped
#      ├── storage.tar.gz    the public disk — sccit_prod_storage volume
#      ├── private.tar.gz    the local disk — private attachments (format /2)
#      ├── row-counts.tsv    exact per-table row counts, taken right after the dump
#      ├── manifest.json     provenance and checksums — flat keys, no credentials
#      └── verification.txt  added by verify-backup.sh once the snapshot has
#                            restored in a disposable database
#  Format sccit.snapshot/1 (before 2026-10-10's private volume) has no
#  private.tar.gz; it still verifies and restores, private files untouched.
# =============================================================

# Git Bash rewrites arguments that look like POSIX paths (/data, /var/lib/...,
# even the '/row/n/text()' XPath below) into Windows paths before Docker sees
# them. Nothing in these scripts passes a host path to Docker, so turn it off.
MSYS_NO_PATHCONV=1
export MSYS_NO_PATHCONV

SNAP_ROOT="$REPO_ROOT/backups/prod"

# Every throwaway container uses the image postgres_prod already runs: it is
# local, so nothing is pulled, and pg_dump/psql/tar versions match production.
SNAP_TOOL_IMAGE='pgvector/pgvector:pg17'

# The disposable database. It is only ever removed when it carries our label,
# so a same-named container that is not ours is never touched.
SNAP_SCRATCH='sccit_backup_verify'

# Where Laravel's `local` disk lives inside the PHP containers
# (backend/config/filesystems.php: storage_path('app/private')).
SNAP_PRIVATE_DIR='/var/www/html/storage/app/private'

# Exact row count of every table, as "table<TAB>count" lines. Exact rather than
# pg_class.reltuples, because an estimate cannot prove two databases agree.
SNAP_COUNT_SQL="SELECT c.relname || chr(9) || (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM %I.%I', n.nspname, c.relname), false, true, '')))[1]::text FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') ORDER BY c.relname"

# The one psql invocation every restore uses — production and the disposable
# proof alike. ON_ERROR_STOP + --single-transaction: any error rolls the whole
# restore back, so a failed restore leaves the target exactly as it was.
SNAP_PSQL_RESTORE='psql -X -q -v ON_ERROR_STOP=1 --single-transaction'

# snap_field <snapshot-dir> <key> — one flat manifest value (same idiom as
# releases.sh's json_field).
snap_field() {
    sed -n "s/.*\"$2\"[[:space:]]*:[[:space:]]*\"\{0,1\}\([^\",}]*\)\"\{0,1\}.*/\1/p" "$1/manifest.json" | head -1
}

snap_sha() { sha256sum "$1" | cut -d' ' -f1; }

# snap_resolve <path> — absolute path of a snapshot directory, accepted relative
# to the current directory or to the repository root.
snap_resolve() {
    for _c in "$1" "$REPO_ROOT/$1"; do
        if [ -d "$_c" ]; then ( cd "$_c" && pwd ); return 0; fi
    done
    return 1
}

# snap_rel <abs-path> — the same path relative to the repository root.
snap_rel() {
    case "$1" in
        "$REPO_ROOT"/*) printf '%s\n' "${1#"$REPO_ROOT"/}" ;;
        *) printf '%s\n' "$1" ;;
    esac
}

# snap_files <snapshot-dir> — the files a snapshot of its format must hold.
snap_files() {
    case "$(snap_field "$1" format)" in
        sccit.snapshot/1) echo 'database.sql.gz storage.tar.gz row-counts.tsv' ;;
        sccit.snapshot/2) echo 'database.sql.gz storage.tar.gz private.tar.gz row-counts.tsv' ;;
        *) return 1 ;;
    esac
}

# snap_has_private <snapshot-dir> — does it carry the private attachments?
snap_has_private() { snap_files "$1" | grep -q 'private.tar.gz'; }

# snap_check <snapshot-dir> — host-side only, no Docker: format, provenance,
# checksums, gzip integrity and the dump's completion footer. A dump truncated
# by a full disk or a killed pg_dump fails here, before anything restores it.
snap_check() {
    _d="$1"
    [ -f "$_d/manifest.json" ] || { fail "no manifest.json — not a snapshot directory"; return 1; }
    _files="$(snap_files "$_d")" \
        || { fail "unknown snapshot format '$(snap_field "$_d" format)'"; return 1; }
    [ "$(snap_field "$_d" stack)" = 'prod' ] \
        || { fail "manifest stack is '$(snap_field "$_d" stack)', not 'prod'"; return 1; }
    [ "$(snap_field "$_d" source_container)" = 'sccit_prod_postgres' ] \
        || { fail "manifest source is '$(snap_field "$_d" source_container)', not sccit_prod_postgres"; return 1; }
    [ "$(snap_field "$_d" database)" = "$DB_NAME" ] \
        || { fail "manifest database is '$(snap_field "$_d" database)', not '$DB_NAME'"; return 1; }
    for _f in $_files; do
        [ -f "$_d/$_f" ] || { fail "missing $_f"; return 1; }
        _key="$(printf '%s' "$_f" | tr '.-' '__')_sha256"
        [ "$(snap_sha "$_d/$_f")" = "$(snap_field "$_d" "$_key")" ] \
            || { fail "$_f does not match the checksum recorded in the manifest"; return 1; }
        case "$_f" in
            *.gz) gzip -t "$_d/$_f" 2>/dev/null || { fail "$_f is not a valid gzip stream"; return 1; } ;;
        esac
    done
    gzip -dc "$_d/database.sql.gz" | tail -n 5 | grep -q 'PostgreSQL database dump complete' \
        || { fail "database.sql.gz is truncated (no 'dump complete' footer)"; return 1; }
    return 0
}

# ---- Volumes and archives ---------------------------------------------------
# Throwaway --network none containers of SNAP_TOOL_IMAGE; the tmpfs covers the
# image's own data VOLUME so none of them ever creates a Docker volume.

# snap_tar_files <archive> — number of regular files in a .tar.gz (host-side).
snap_tar_files() { tar -tvzf "$1" | grep -c '^-' || true; }

# snap_archive_volume <volume> <archive> — the whole volume, mounted read-only.
snap_archive_volume() {
    docker run --rm --pull=never --network none \
        --tmpfs /var/lib/postgresql/data -v "$1:/data:ro" \
        --entrypoint tar "$SNAP_TOOL_IMAGE" -czf - --numeric-owner -C /data . > "$2"
}

# snap_extract_count <archive> — extract into tmpfs (proves it unpacks) and
# print how many regular files came out.
snap_extract_count() {
    docker run --rm -i --pull=never --network none \
        --tmpfs /var/lib/postgresql/data --tmpfs /restore \
        --entrypoint sh "$SNAP_TOOL_IMAGE" \
        -c 'tar -xzpf - --numeric-owner -C /restore && find /restore -type f | wc -l' \
        < "$1" | tr -d ' \r'
}

# snap_restore_volume <volume> <archive> — replace a volume's contents with the
# archive's, owners and modes preserved. Extracted beside the live files first;
# only a complete extraction replaces them.
snap_restore_volume() {
    docker run --rm -i --pull=never --network none \
        --tmpfs /var/lib/postgresql/data -v "$1:/data" \
        --entrypoint sh "$SNAP_TOOL_IMAGE" -c '
            set -e
            rm -rf /data/.sccit-restore && mkdir /data/.sccit-restore
            tar -xzpf - --numeric-owner -C /data/.sccit-restore
            find /data -mindepth 1 -maxdepth 1 ! -name .sccit-restore -exec rm -rf {} +
            ( cd /data/.sccit-restore && tar -cf - . ) | tar -xpf - --numeric-owner -C /data
            rm -rf /data/.sccit-restore' \
        < "$2"
}

# snap_private_mount <container> — name of the volume mounted on the private
# disk in that container; empty when the disk is still the container's layer.
snap_private_mount() {
    docker inspect -f "{{range .Mounts}}{{if eq .Destination \"$SNAP_PRIVATE_DIR\"}}{{.Name}}{{end}}{{end}}" "$1" 2>/dev/null
}

# ---- The disposable database ----------------------------------------------

snap_scratch_stop() {
    if [ "$(docker inspect -f '{{index .Config.Labels "sccit.purpose"}}' "$SNAP_SCRATCH" 2>/dev/null)" = 'backup-verify' ]; then
        docker rm -f "$SNAP_SCRATCH" >/dev/null 2>&1
    fi
    return 0
}

# --network none: it cannot reach production, and nothing can reach it.
# --tmpfs over the image's data VOLUME: no Docker volume is ever created.
# trust auth is safe only because of --network none (local socket only).
snap_scratch_start() {
    snap_scratch_stop
    if docker inspect "$SNAP_SCRATCH" >/dev/null 2>&1; then
        fail "a container named $SNAP_SCRATCH exists and is not ours — refusing to touch it"
        return 1
    fi
    docker run -d --pull=never --name "$SNAP_SCRATCH" --label sccit.purpose=backup-verify \
        --network none --tmpfs /var/lib/postgresql/data \
        -e POSTGRES_USER="$DB_USER" -e POSTGRES_DB="$DB_NAME" -e POSTGRES_HOST_AUTH_METHOD=trust \
        "$SNAP_TOOL_IMAGE" >/dev/null || return 1
    # Ready means the image's first-run initialisation has finished AND the
    # final server is up — the init phase runs a temporary server that would
    # accept a connection and then shut down underneath it.
    _i=0
    until docker logs "$SNAP_SCRATCH" 2>&1 | grep -q 'PostgreSQL init process complete' \
            && docker exec "$SNAP_SCRATCH" pg_isready -q -U "$DB_USER" -d "$DB_NAME" >/dev/null 2>&1; do
        _i=$((_i + 1))
        [ "$_i" -ge 90 ] && { fail "the disposable database did not become ready"; return 1; }
        sleep 1
    done
    return 0
}

snap_scratch_psql() { docker exec -i "$SNAP_SCRATCH" "$@"; }

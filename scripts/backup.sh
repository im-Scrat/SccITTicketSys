#!/usr/bin/env sh
# =============================================================
#  SccIT — production snapshot: database + uploads + manifest.
#
#      scripts/backup.sh --stack prod [--label LABEL] [--verify] [--keep N]
#
#  Writes backups/prod/<UTC-timestamp>[-label]/ — the layout is described in
#  scripts/lib/snapshot.sh and docs/OPERATIONS.md §2.
#
#  OUTPUT CONTRACT
#  Progress goes to stderr. The ONLY stdout line is the snapshot path, printed
#  last and only on success: deploy-prod.sh and rollback-prod.sh read it with
#  `tail -1`, so a failed backup must hand them an empty string, never a path
#  that looks like a restore point. (The pre-WP-2.7d script this replaces
#  printed "Backup written: ..." even when pg_dump had failed — and dumped the
#  development database whatever --stack said.)
#
#  READ-ONLY AGAINST PRODUCTION. pg_dump runs inside postgres_prod over its
#  local socket, so no password is passed or printed; the public and private
#  upload volumes are mounted :ro into throwaway --network none containers
#  (before the private volume is deployed, its files are read with `tar` from
#  inside app_prod).
#
#  --verify  also prove the snapshot restores, in a disposable database
#            (scripts/verify-backup.sh). Without it the snapshot is integrity-
#            checked only: written, not yet proven restorable.
#  --keep N  afterwards, prune to the N newest snapshots. Opt-in — by default
#            nothing is ever deleted. Never touches loose files, partial
#            directories, or the pre-deploy snapshot of releases/current.
# =============================================================
set -eu

. "$(dirname "$0")/lib/stack.sh"

STACK_ARG=''
LABEL=''
KEEP=0
VERIFY=0

while [ $# -gt 0 ]; do
    case "$1" in
        --stack) STACK_ARG="${2:?--stack needs prod}"; shift 2 ;;
        --label) LABEL="${2:?--label needs a value}"; shift 2 ;;
        --keep) KEEP="${2:?--keep needs a number}"; shift 2 ;;
        --verify) VERIFY=1; shift ;;
        -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
        *) die "unknown argument: $1" ;;
    esac
done

[ -n "$STACK_ARG" ] || die "pass --stack prod (production is the only stack)."
stack_select "$STACK_ARG"          # 'dev' stops here: that stack is retired
[ "$STACK" = 'prod' ] || die "backup.sh snapshots the production stack only."
. "$(dirname "$0")/lib/snapshot.sh"

case "$LABEL" in *[!A-Za-z0-9._-]*) die "--label may only use letters, digits, '.', '_' and '-'." ;; esac
case "$KEEP" in ''|*[!0-9]*) die "--keep needs a whole number." ;; esac

# Snapshots hold real rows (password hashes included) and real uploads.
umask 077

take_snapshot() {
    dc ps --status running --services 2>/dev/null | grep -qx "$DB_SVC" \
        || die "the production database ($DB_SVC) is not running — nothing to snapshot."

    mkdir -p "$SNAP_ROOT"
    NAME="$(date -u +%Y%m%dT%H%M%SZ)${LABEL:+-$LABEL}"
    DIR="$SNAP_ROOT/$NAME"
    PART="$SNAP_ROOT/.$NAME.partial"
    [ ! -e "$DIR" ] || die "$(snap_rel "$DIR") already exists."
    # Any failure below removes the half-written directory: a snapshot either
    # exists complete under its final name or does not exist at all.
    trap 'rm -rf "$PART"' EXIT
    mkdir "$PART"

    step "Snapshot $(snap_rel "$DIR")"

    # 1. Database. POSIX sh has no pipefail, so pg_dump's own exit status is
    #    carried out of the pipeline in a file — gzip succeeding proves nothing.
    { db_exec pg_dump --clean --if-exists -U "$DB_USER" -d "$DB_NAME"; echo $? > "$PART/.rc"; } \
        | gzip -6 > "$PART/database.sql.gz"
    [ "$(cat "$PART/.rc" 2>/dev/null)" = '0' ] || die "pg_dump failed — no snapshot was written."
    rm -f "$PART/.rc"
    gzip -dc "$PART/database.sql.gz" | tail -n 5 | grep -q 'PostgreSQL database dump complete' \
        || die "the dump is incomplete — no snapshot was written."
    ok "database   $DB_NAME, $(wc -c < "$PART/database.sql.gz" | tr -d ' ') bytes gzipped"

    # 2. Exact row counts and the migration count, read straight after the
    #    dump. verify-backup.sh compares a restored copy against them.
    db_exec psql -X -tA -U "$DB_USER" -d "$DB_NAME" -c "$SNAP_COUNT_SQL" | tr -d '\r' > "$PART/row-counts.tsv"
    TABLES="$(wc -l < "$PART/row-counts.tsv" | tr -d ' ')"
    MIGRATIONS="$(db_exec psql -X -tA -U "$DB_USER" -d "$DB_NAME" -c 'SELECT count(*) FROM migrations' | tr -d ' \r')"
    [ "$TABLES" -gt 0 ] || die "could not read the table list — no snapshot was written."
    case "$MIGRATIONS" in ''|*[!0-9]*) die "could not read the migration count — no snapshot was written." ;; esac
    ok "counts     $TABLES tables, $MIGRATIONS migrations"

    # 3. Public disk, mounted read-only.
    snap_archive_volume "$STORAGE_VOLUME" "$PART/storage.tar.gz" \
        || die "could not archive the $STORAGE_VOLUME volume — no snapshot was written."
    UPLOADS="$(snap_tar_files "$PART/storage.tar.gz")"
    ok "public     $STORAGE_VOLUME, $UPLOADS file(s)"

    # 4. Private disk: ticket attachments, repair photos, work-support and
    #    asset files. From its own volume once the 2026-10-10 fix is deployed.
    #    Until then app_prod's container layer is the ONLY copy, so it is read
    #    from there — which makes the rollout's pre-deploy snapshot the
    #    backfill source for the new volume.
    APP_CID="$(dc ps -aq "$APP_SVC" 2>/dev/null | head -1)"
    MOUNTED=''
    if [ -n "$APP_CID" ]; then MOUNTED="$(snap_private_mount "$APP_CID")"; fi
    if [ "$MOUNTED" = "$PRIVATE_VOLUME" ] \
            || { [ -z "$APP_CID" ] && docker volume inspect "$PRIVATE_VOLUME" >/dev/null 2>&1; }; then
        PRIVATE_SOURCE='volume'
        snap_archive_volume "$PRIVATE_VOLUME" "$PART/private.tar.gz" \
            || die "could not archive the $PRIVATE_VOLUME volume — no snapshot was written."
        PRIVATE_FROM="$PRIVATE_VOLUME"
    elif [ -n "$APP_CID" ] && dc ps --status running --services 2>/dev/null | grep -qx "$APP_SVC"; then
        PRIVATE_SOURCE='container'
        app_exec tar -czf - --numeric-owner -C "$SNAP_PRIVATE_DIR" . > "$PART/private.tar.gz" \
            || die "could not archive $SNAP_PRIVATE_DIR from $APP_SVC — no snapshot was written."
        PRIVATE_FROM="$APP_SVC container layer ($PRIVATE_VOLUME not deployed yet)"
    else
        die "the private attachments are unreachable: $APP_SVC is not running and has no
       $PRIVATE_VOLUME volume — no snapshot was written."
    fi
    PRIVATE_FILES="$(snap_tar_files "$PART/private.tar.gz")"
    ok "private    $PRIVATE_FROM, $PRIVATE_FILES file(s)"

    # 5. Manifest: which code this data was shaped for, and checksums. Flat
    #    keys so the sed-based readers in these scripts can parse it.
    APP_IMAGE="$(docker inspect -f '{{.Image}}' "$(dc ps -q "$APP_SVC")" 2>/dev/null || echo unknown)"
    WEB_IMAGE="$(docker inspect -f '{{.Image}}' "$(dc ps -q "$WEB_SVC")" 2>/dev/null || echo unknown)"
    SOURCE="$(docker inspect -f '{{.Name}}' "$(dc ps -q "$DB_SVC")" | sed 's#^/##')"
    PG_VERSION="$(db_exec pg_dump --version | tr -d '\r')"
    GIT_COMMIT="$(cd "$REPO_ROOT" && git rev-parse HEAD 2>/dev/null || echo unknown)"
    GIT_BRANCH="$(cd "$REPO_ROOT" && git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
    GIT_DIRTY=false
    if [ -n "$(cd "$REPO_ROOT" && git status --porcelain 2>/dev/null)" ]; then GIT_DIRTY=true; fi
    {
        printf '{\n'
        printf '  "format": "sccit.snapshot/2",\n'
        printf '  "stack": "prod",\n'
        printf '  "created_at_utc": "%s",\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        printf '  "label": "%s",\n' "$LABEL"
        printf '  "database": "%s",\n' "$DB_NAME"
        printf '  "source_container": "%s",\n' "$SOURCE"
        printf '  "postgres_version": "%s",\n' "$PG_VERSION"
        printf '  "git_commit": "%s",\n' "$GIT_COMMIT"
        printf '  "git_branch": "%s",\n' "$GIT_BRANCH"
        printf '  "git_dirty": %s,\n' "$GIT_DIRTY"
        printf '  "app_image": "%s",\n' "$APP_IMAGE"
        printf '  "web_image": "%s",\n' "$WEB_IMAGE"
        printf '  "migration_count": %s,\n' "$MIGRATIONS"
        printf '  "table_count": %s,\n' "$TABLES"
        printf '  "upload_file_count": %s,\n' "$UPLOADS"
        printf '  "database_sql_gz_sha256": "%s",\n' "$(snap_sha "$PART/database.sql.gz")"
        printf '  "database_sql_gz_bytes": %s,\n' "$(wc -c < "$PART/database.sql.gz" | tr -d ' ')"
        printf '  "storage_tar_gz_sha256": "%s",\n' "$(snap_sha "$PART/storage.tar.gz")"
        printf '  "storage_tar_gz_bytes": %s,\n' "$(wc -c < "$PART/storage.tar.gz" | tr -d ' ')"
        printf '  "private_source": "%s",\n' "$PRIVATE_SOURCE"
        printf '  "private_file_count": %s,\n' "$PRIVATE_FILES"
        printf '  "private_tar_gz_sha256": "%s",\n' "$(snap_sha "$PART/private.tar.gz")"
        printf '  "private_tar_gz_bytes": %s,\n' "$(wc -c < "$PART/private.tar.gz" | tr -d ' ')"
        printf '  "row_counts_tsv_sha256": "%s"\n' "$(snap_sha "$PART/row-counts.tsv")"
        printf '}\n'
    } > "$PART/manifest.json"

    chmod 600 "$PART"/* 2>/dev/null || true
    mv "$PART" "$DIR"
    trap - EXIT
    ok "manifest   commit $(printf '%.7s' "$GIT_COMMIT"), checksums recorded"

    if [ "$VERIFY" -eq 1 ]; then
        sh "$REPO_ROOT/scripts/verify-backup.sh" "$DIR" \
            || die "$(snap_rel "$DIR") was written but FAILED verification — do not rely on it."
    else
        warn "not yet proven restorable: sh scripts/verify-backup.sh $(snap_rel "$DIR")"
    fi
}

# Opt-in retention. Only well-formed snapshot directories are candidates, and
# the snapshot the current release would be rolled back with is always kept —
# pruning the rollback target to save disk is how retention becomes the outage.
prune() {
    [ "$KEEP" -gt 0 ] || return 0
    _protected=''
    _cur="$(tr -d ' \r\n' < "$REPO_ROOT/releases/current" 2>/dev/null || true)"
    if [ -n "$_cur" ] && [ -f "$REPO_ROOT/releases/$_cur/manifest.json" ]; then
        _protected="$(sed -n 's/.*"pre_deploy_snapshot"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' \
            "$REPO_ROOT/releases/$_cur/manifest.json" | head -1)"
        _protected="${_protected##*/}"
    fi
    step "Retention: keep the $KEEP newest"
    ls -1 "$SNAP_ROOT" | grep -E '^[0-9]{8}T[0-9]{6}Z(-[A-Za-z0-9._-]+)?$' | sort -r | sed "1,${KEEP}d" \
        | while read -r _old; do
            if [ "$_old" = "$_protected" ]; then ok "kept     $_old (pre-deploy snapshot of the current release)"; continue; fi
            [ -f "$SNAP_ROOT/$_old/manifest.json" ] || continue
            rm -rf "${SNAP_ROOT:?}/$_old" && ok "pruned   $_old"
        done
}

take_snapshot >&2
prune >&2
snap_rel "$DIR"

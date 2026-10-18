#!/usr/bin/env sh
# =============================================================
#  SccIT — restore a production snapshot.   THIS OVERWRITES PRODUCTION DATA.
#
#      scripts/restore.sh --stack prod [--dry-run] [--db-only] [--yes] backups/prod/<snapshot>
#
#  Every check runs before anything is changed:
#    1  the argument must be a prod snapshot DIRECTORY whose manifest, checksums,
#       gzip streams and dump footer all check out (host-side, no Docker).
#       Loose dumps (*.sql.gz, *.dump) are refused: no manifest, no provenance.
#    2  no terminal and no --yes -> refuse (--yes is for automation, e.g.
#       rollback-prod.sh, which asks its own question first)
#    3  the snapshot must restore cleanly in a disposable database first
#       (verify-backup.sh) — production is never the first place it is tried
#    4  show what will be overwritten: live vs snapshot migrations and rows;
#       warn when the snapshot is AHEAD of the running code (the dangerous way)
#       --dry-run stops here, having changed nothing
#    5  type "prod" to confirm (unless --yes)
#  Then, and only then:
#    6  a verified safety snapshot of the current state — abort if it fails
#    7  quiesce writers: stop the queue, AI and scheduler containers and put
#       app_prod in maintenance mode (APP_MAINTENANCE_DRIVER=file is
#       per-container, so `artisan down` alone would not pause the workers)
#    8  restore the database in ONE transaction (any error rolls it all back)
#    9  restore the public and private upload volumes, each staged inside the
#       volume and then swapped (--db-only skips both). A snapshot carrying
#       private attachments needs the sccit_prod_private volume deployed —
#       checked in step 4, before anything changes.
#   10  bring everything back — also when a step fails — and check migrations
# =============================================================
set -eu

. "$(dirname "$0")/lib/stack.sh"

STACK_ARG=''
SNAP_ARG=''
DRY_RUN=0
DB_ONLY=0
ASSUME_YES=0

while [ $# -gt 0 ]; do
    case "$1" in
        --stack) STACK_ARG="${2:?--stack needs prod}"; shift 2 ;;
        --dry-run) DRY_RUN=1; shift ;;
        --db-only) DB_ONLY=1; shift ;;
        --yes|-y) ASSUME_YES=1; shift ;;
        -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
        -*) die "unknown argument: $1" ;;
        *) [ -z "$SNAP_ARG" ] || die "one snapshot at a time."; SNAP_ARG="$1"; shift ;;
    esac
done

[ -n "$STACK_ARG" ] || die "pass --stack prod (production is the only stack)."
stack_select "$STACK_ARG"          # 'dev' stops here: that stack is retired
[ "$STACK" = 'prod' ] || die "restore.sh restores the production stack only."
umask 077                          # temp files below hold the full decompressed dump
. "$(dirname "$0")/lib/snapshot.sh"
[ -n "$SNAP_ARG" ] || die "usage: sh scripts/restore.sh --stack prod [--dry-run] backups/prod/<snapshot>"

case "$SNAP_ARG" in
    *.sql|*.sql.gz|*.dump|*.meta.json)
        die "loose dump files are not restored by this script — they carry no manifest,
       so nothing proves which database or code they belong to. Restore one by
       hand into a disposable database first; see docs/OPERATIONS.md §2." ;;
esac
SNAP="$(snap_resolve "$SNAP_ARG")" || die "snapshot directory not found: $SNAP_ARG"
REL="$(snap_rel "$SNAP")"

# ---- 1. Host-side checks -------------------------------------------------
step "1/7  Snapshot checks"
snap_check "$SNAP" || die "refusing to restore $REL — nothing was changed."
ok "$REL: prod / $(snap_field "$SNAP" source_container) / $DB_NAME, taken $(snap_field "$SNAP" created_at_utc) at commit $(snap_field "$SNAP" git_commit | cut -c1-7)"

# ---- 2. Interactive, or explicitly automated -----------------------------
if [ "$DRY_RUN" -eq 0 ] && [ "$ASSUME_YES" -eq 0 ] && [ ! -t 0 ]; then
    die "no terminal to confirm on. Run this by hand, or pass --yes from automation.
       Nothing was changed."
fi

# ---- 3. Prove it restores, somewhere that is not production --------------
step "2/7  Isolated restore test"
sh "$REPO_ROOT/scripts/verify-backup.sh" "$SNAP" \
    || die "$REL did not restore cleanly in isolation — production was not touched."

# ---- 4. What will be overwritten (read-only) -----------------------------
step "3/7  What would change"
require_stack_running || exit 1
dc ps --status running --services 2>/dev/null | grep -qx "$DB_SVC" \
    || die "the production database ($DB_SVC) is not running."

LIVE_COUNTS="$(mktemp)"
db_exec psql -X -tA -U "$DB_USER" -d "$DB_NAME" -c "$SNAP_COUNT_SQL" | tr -d '\r' > "$LIVE_COUNTS"
LIVE_MIG="$(db_exec psql -X -tA -U "$DB_USER" -d "$DB_NAME" -c 'SELECT count(*) FROM migrations' | tr -d ' \r')"
CODE_MIG="$(app_exec sh -c 'ls /var/www/html/database/migrations/*.php | wc -l' | tr -d ' \r')"
SNAP_MIG="$(snap_field "$SNAP" migration_count)"

say "  target     stack sccit_prod, container $(snap_field "$SNAP" source_container), database $DB_NAME"
# Private attachments are only restorable into the volume: written into
# app_prod's own layer they would vanish at the next recreate. So a snapshot
# that carries them needs the volume deployed first (or --db-only).
PRIVATE_BLOCKED=0
if [ "$DB_ONLY" -eq 1 ]; then
    say "  uploads    public and private files left as they are (--db-only)"
else
    say "  public     volume $STORAGE_VOLUME replaced by $(snap_field "$SNAP" upload_file_count) file(s) from the snapshot"
    if snap_has_private "$SNAP"; then
        APP_CID="$(dc ps -aq "$APP_SVC" 2>/dev/null | head -1)"
        if [ -n "$APP_CID" ] && [ "$(snap_private_mount "$APP_CID")" = "$PRIVATE_VOLUME" ]; then
            say "  private    volume $PRIVATE_VOLUME replaced by $(snap_field "$SNAP" private_file_count) file(s) from the snapshot"
        else
            PRIVATE_BLOCKED=1
            warn "the $PRIVATE_VOLUME volume is not deployed (app_prod has no volume on"
            warn "  $SNAP_PRIVATE_DIR): a real run stops here. Deploy it first, or pass --db-only."
        fi
    else
        warn "this format /1 snapshot has no private attachments; those are left as they are."
    fi
fi
say "  migrations live $LIVE_MIG -> snapshot $SNAP_MIG (the running image ships $CODE_MIG)"
TAB="$(printf '\t')"
for T in users tickets assets pc_units rooms maintenance_records notifications; do
    L="$(awk -F "$TAB" -v t="$T" '$1 == t { print $2 }' "$LIVE_COUNTS")"
    S="$(awk -F "$TAB" -v t="$T" '$1 == t { print $2 }' "$SNAP/row-counts.tsv")"
    if [ -n "$L$S" ]; then say "  rows       $T: live ${L:-?} -> snapshot ${S:-?}"; fi
done
rm -f "$LIVE_COUNTS"
case "$CODE_MIG" in
    ''|*[!0-9]*) warn "could not count the running image's migrations — compare by hand before restoring." ;;
    *)
if [ "$SNAP_MIG" -gt "$CODE_MIG" ]; then
    warn "the snapshot is AHEAD of the running code ($SNAP_MIG vs $CODE_MIG migrations):"
    warn "  this image does not know part of that schema. Deploy the matching release first."
elif [ "$SNAP_MIG" -lt "$CODE_MIG" ]; then
    warn "the snapshot predates $((CODE_MIG - SNAP_MIG)) migration(s) of the running code;"
    warn "  afterwards run: docker compose -p sccit_prod -f compose.prod.yaml exec -T app_prod php artisan migrate --force"
fi
    ;;
esac

if [ "$DRY_RUN" -eq 1 ]; then
    say ""
    say "--dry-run: nothing was changed. A real run would now: take a verified safety"
    say "snapshot, stop queue_prod/queue_ai_prod/queue_bulk_prod/scheduler_prod, put app_prod into"
    say "maintenance mode, restore in one transaction$( [ "$DB_ONLY" -eq 1 ] || printf ', swap the uploads'), and bring it all back."
    exit 0
fi
[ "$PRIVATE_BLOCKED" -eq 0 ] \
    || die "the $PRIVATE_VOLUME volume is not deployed — deploy it first, or pass --db-only. Nothing was changed."

# ---- 5. Confirm ----------------------------------------------------------
step "4/7  Confirm"
if [ "$ASSUME_YES" -ne 1 ]; then
    printf '  This OVERWRITES the production database%s.\n  Type "prod" to proceed: ' \
        "$( [ "$DB_ONLY" -eq 1 ] || printf ' and uploads')"
    read -r REPLY || REPLY=''
    [ "$REPLY" = 'prod' ] || die "aborted — nothing was changed."
fi

# ---- 6. Safety snapshot --------------------------------------------------
step "5/7  Safety snapshot of the current state"
SAFETY="$(sh "$REPO_ROOT/scripts/backup.sh" --stack prod --label pre-restore --verify | tail -1)"
{ [ -n "$SAFETY" ] && [ -d "$REPO_ROOT/$SAFETY" ]; } \
    || die "could not take a verified safety snapshot — nothing was changed."
ok "undo with: sh scripts/restore.sh --stack prod $SAFETY"

# ---- 7. Quiesce, restore, and always come back ---------------------------
# queue_bulk_prod (Phase 2.10) writes report files and, from R6, imported records.
WRITERS='queue_prod queue_ai_prod queue_bulk_prod scheduler_prod'
SQL="$(mktemp)"
resume() {
    app_exec php artisan up >/dev/null 2>&1 || warn "artisan up failed — run it by hand."
    # shellcheck disable=SC2086  # WRITERS is a service list
    dc start $WRITERS >/dev/null 2>&1 || warn "could not start $WRITERS — start them by hand."
    rm -f "$SQL" "$SQL.log"
}
trap resume EXIT

step "6/7  Restore"
# shellcheck disable=SC2086
dc stop $WRITERS >/dev/null
app_exec php artisan down --retry=30 >/dev/null || die "could not enter maintenance mode."
ok "writers stopped, app_prod in maintenance mode"

# Decompressed to a file first: a pipe would let a mid-stream read error end
# the input early, and psql would commit whatever had arrived.
gzip -dc "$SNAP/database.sql.gz" > "$SQL"
# shellcheck disable=SC2086
if dc exec -T -e PGOPTIONS='-c lock_timeout=60s' "$DB_SVC" $SNAP_PSQL_RESTORE -U "$DB_USER" -d "$DB_NAME" \
        < "$SQL" > /dev/null 2> "$SQL.log"; then
    ok "database restored (one transaction)"
else
    tail -n 5 "$SQL.log" >&2
    die "the database restore failed and was rolled back — production data is unchanged."
fi

if [ "$DB_ONLY" -eq 0 ]; then
    snap_restore_volume "$STORAGE_VOLUME" "$SNAP/storage.tar.gz" \
        || die "the public uploads restore failed (the database WAS restored). Undo with:
       sh scripts/restore.sh --stack prod $SAFETY"
    ok "public uploads restored ($STORAGE_VOLUME)"
    if snap_has_private "$SNAP"; then
        snap_restore_volume "$PRIVATE_VOLUME" "$SNAP/private.tar.gz" \
            || die "the private attachments restore failed (database and public uploads WERE restored).
       Undo with: sh scripts/restore.sh --stack prod $SAFETY"
        ok "private attachments restored ($PRIVATE_VOLUME)"
    fi
fi

# ---- 8. Back up and check ------------------------------------------------
step "7/7  Resume"
trap - EXIT
resume
AFTER_MIG="$(db_exec psql -X -tA -U "$DB_USER" -d "$DB_NAME" -c 'SELECT count(*) FROM migrations' | tr -d ' \r')"
if [ "$AFTER_MIG" = "$SNAP_MIG" ]; then ok "migrations: $AFTER_MIG, as in the snapshot"
else warn "migrations: $AFTER_MIG, the snapshot recorded $SNAP_MIG"; fi
say ""
say "Restored $REL. Undo with: sh scripts/restore.sh --stack prod $SAFETY"
say "Cached values in Redis may be stale until they expire."

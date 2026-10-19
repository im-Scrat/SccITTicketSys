#!/usr/bin/env sh
# =============================================================
#  SccIT — prove a production snapshot restores, without touching production.
#
#      scripts/verify-backup.sh backups/prod/<snapshot>
#
#  1  host-side integrity: manifest provenance, checksums, gzip, dump footer
#  2  restore into a disposable PostgreSQL (the production image; --network
#     none; tmpfs; removed on exit) TWICE — into an empty database, then again
#     over the populated result. The second pass is the --clean path a real
#     restore takes against a live database, so it is the one that matters.
#     Both use restore.sh's exact psql invocation (scripts/lib/snapshot.sh).
#  3  contents: table count, migrations, and every table's exact row count
#     against row-counts.tsv (a difference means rows were written between the
#     dump and the count — reported per table, the restore itself still stands)
#  4  the public and private upload archives, each extracted into tmpfs and
#     counted against the manifest (format /1 snapshots have no private one)
#
#  On success writes verification.txt into the snapshot. Exit 0 = proven.
#  Production is never contacted: everything runs on the snapshot's files.
# =============================================================
set -eu

. "$(dirname "$0")/lib/stack.sh"
stack_select prod
. "$(dirname "$0")/lib/snapshot.sh"

case "${1:-}" in
    ''|-h|--help) sed -n '2,21p' "$0"; [ -n "${1:-}" ] && exit 0; exit 2 ;;
esac
SNAP="$(snap_resolve "$1")" || die "snapshot directory not found: $1"
REL="$(snap_rel "$SNAP")"

step "Verify $REL"

# ---- 1. Integrity --------------------------------------------------------
snap_check "$SNAP" || die "$REL failed its integrity checks — it is not restorable."
ok "integrity  provenance (prod / $(snap_field "$SNAP" source_container) / $DB_NAME), checksums, gzip, dump footer"

WORK="$(mktemp -d)"
cleanup() { rm -rf "$WORK"; snap_scratch_stop; }
trap cleanup EXIT INT TERM

# ---- 2. Restore, twice ---------------------------------------------------
snap_scratch_start || die "could not start the disposable database."
ok "scratch    $SNAP_SCRATCH ($SNAP_TOOL_IMAGE, --network none, tmpfs)"

gzip -dc "$SNAP/database.sql.gz" > "$WORK/database.sql"
for PASS in 1 2; do
    # shellcheck disable=SC2086  # SNAP_PSQL_RESTORE is a word list by design
    if snap_scratch_psql $SNAP_PSQL_RESTORE -U "$DB_USER" -d "$DB_NAME" \
            < "$WORK/database.sql" > /dev/null 2> "$WORK/pass$PASS.log"; then
        if [ "$PASS" -eq 1 ]; then ok "restore 1  into an empty database"
        else ok "restore 2  --clean over the populated copy (the live-restore path)"; fi
    else
        tail -n 5 "$WORK/pass$PASS.log" >&2
        die "restore pass $PASS failed — $REL is NOT restorable."
    fi
done

# ---- 3. Contents ---------------------------------------------------------
snap_scratch_psql psql -X -tA -U "$DB_USER" -d "$DB_NAME" -c "$SNAP_COUNT_SQL" \
    | tr -d '\r' > "$WORK/restored.tsv"
TABLES_EXPECTED="$(snap_field "$SNAP" table_count)"
TABLES_GOT="$(wc -l < "$WORK/restored.tsv" | tr -d ' ')"
[ "$TABLES_GOT" = "$TABLES_EXPECTED" ] \
    || die "restored $TABLES_GOT tables; the snapshot recorded $TABLES_EXPECTED."

MIG_EXPECTED="$(snap_field "$SNAP" migration_count)"
MIG_GOT="$(snap_scratch_psql psql -X -tA -U "$DB_USER" -d "$DB_NAME" -c 'SELECT count(*) FROM migrations' | tr -d ' \r')"
[ "$MIG_GOT" = "$MIG_EXPECTED" ] \
    || die "restored $MIG_GOT migrations; the snapshot recorded $MIG_EXPECTED."
ok "schema     $TABLES_GOT tables, $MIG_GOT migrations"

TAB="$(printf '\t')"
DRIFT=0
ROWS=0
while IFS="$TAB" read -r T N; do
    G="$(awk -F "$TAB" -v t="$T" '$1 == t { print $2 }' "$WORK/restored.tsv")"
    ROWS=$((ROWS + ${G:-0}))
    if [ "$G" != "$N" ]; then
        DRIFT=$((DRIFT + 1))
        warn "rows       $T: restored ${G:-none}, counted $N right after the dump"
    fi
done < "$SNAP/row-counts.tsv"
if [ "$DRIFT" -eq 0 ]; then
    ok "rows       all $TABLES_GOT tables match the recorded counts exactly ($ROWS rows)"
else
    warn "$DRIFT table(s) differ: rows were written between the dump and the count."
fi

# ---- 4. Uploads ----------------------------------------------------------
UP_EXPECTED="$(snap_field "$SNAP" upload_file_count)"
UP_GOT="$(snap_extract_count "$SNAP/storage.tar.gz")"
[ "$UP_GOT" = "$UP_EXPECTED" ] \
    || die "the public-disk archive extracted ${UP_GOT:-nothing} file(s); the snapshot recorded $UP_EXPECTED."
ok "public     $UP_GOT file(s) extract cleanly"

PRIV_GOT='none (format /1)'
if snap_has_private "$SNAP"; then
    PRIV_EXPECTED="$(snap_field "$SNAP" private_file_count)"
    PRIV_GOT="$(snap_extract_count "$SNAP/private.tar.gz")"
    [ "$PRIV_GOT" = "$PRIV_EXPECTED" ] \
        || die "the private-disk archive extracted ${PRIV_GOT:-nothing} file(s); the snapshot recorded $PRIV_EXPECTED."
    ok "private    $PRIV_GOT file(s) extract cleanly (read from the $(snap_field "$SNAP" private_source))"
else
    warn "private    not in this snapshot — format /1, taken before private attachments were archived"
fi

# ---- Record --------------------------------------------------------------
if [ "$DRIFT" -eq 0 ]; then RESULT='VERIFIED'; else RESULT="VERIFIED, $DRIFT table(s) with post-dump drift"; fi
umask 077
{
    printf 'result: %s\n' "$RESULT"
    printf 'verified_at_utc: %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf 'method: restored twice (empty, then --clean over the copy) into a disposable %s container, --network none, tmpfs\n' "$SNAP_TOOL_IMAGE"
    printf 'restore_command: %s\n' "$SNAP_PSQL_RESTORE"
    printf 'tables: %s\nmigrations: %s\nrows: %s\ndrifted_tables: %s\nupload_files: %s\nprivate_files: %s\n' \
        "$TABLES_GOT" "$MIG_GOT" "$ROWS" "$DRIFT" "$UP_GOT" "$PRIV_GOT"
    printf 'production_contacted: no\n'
} > "$SNAP/verification.txt"
ok "result     $RESULT — recorded in $REL/verification.txt"

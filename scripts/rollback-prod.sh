#!/usr/bin/env sh
# =============================================================
#  SccIT — production rollback (WP-2.7d).
#
#      scripts/rollback-prod.sh [--list] [--yes] [--restore-db <snapshot>] <release>
#
#  Rolls the production stack back to a retained release by moving the floating
#  `sccit/{app,web}:prod` tags onto that release's immutable `:prod-<sha>` tags
#  and recreating the services. No rebuild, no registry, no network — which is
#  the point: rollback must work when the thing that broke is the build.
#
#  ── The part that is NOT automatic ───────────────────────────────────────
#  Rolling back CODE does not roll back the DATABASE. Laravel migrations are
#  forward-only in this project, and `migrate:rollback` on a production dataset
#  is a data-loss operation, not a safety net. So:
#
#    * If the release you are going back to expects FEWER migrations than are
#      applied, the older code will run against a newer schema. Additive
#      migrations (which is all of Phases 2.4–2.6b) are usually survivable —
#      extra columns and tables the old code ignores. This script detects the
#      mismatch and says so plainly rather than pretending it is fine.
#
#    * To go back to the data as well, pass --restore-db with the pre-deploy
#      snapshot named in the failed release's manifest. That IS destructive and
#      is confirmed separately.
#
#  Always finishes with the smoke test, because a rollback that was not verified
#  is just a second unverified deployment.
# =============================================================
set -eu

. "$(dirname "$0")/lib/stack.sh"

stack_select prod

RELEASES="$REPO_ROOT/releases"
ASSUME_YES=0
RESTORE_DB=''
TARGET=''

while [ $# -gt 0 ]; do
    case "$1" in
        --list) sh "$REPO_ROOT/scripts/releases.sh" list; exit 0 ;;
        --yes|-y) ASSUME_YES=1; shift ;;
        --restore-db) RESTORE_DB="${2:?--restore-db needs a snapshot path}"; shift 2 ;;
        -h|--help) sed -n '2,28p' "$0"; exit 0 ;;
        -*) die "unknown argument: $1" ;;
        *) TARGET="$1"; shift ;;
    esac
done

if [ -z "$TARGET" ]; then
    say "usage: scripts/rollback-prod.sh <release>"
    say ""
    sh "$REPO_ROOT/scripts/releases.sh" list
    exit 2
fi

CURRENT="$(cat "$RELEASES/current" 2>/dev/null || echo '')"
MANIFEST="$RELEASES/$TARGET/manifest.json"
json_field() { sed -n "s/.*\"$2\"[[:space:]]*:[[:space:]]*\"\{0,1\}\([^\",}]*\)\"\{0,1\}.*/\1/p" "$1" | head -1; }

# =========================================================================
# 1. Verify the artifact still exists
# =========================================================================
step "1/5  Verify release artifact"

for kind in app web; do
    docker image inspect "sccit/$kind:prod-$TARGET" >/dev/null 2>&1 \
        || die "image sccit/$kind:prod-$TARGET is not present locally.
       It was pruned, or this release predates the WP-2.7d release tagging.
       Available releases:  sh scripts/rollback-prod.sh --list"
    ok "sccit/$kind:prod-$TARGET present"
done

if [ -f "$MANIFEST" ]; then
    ok "manifest: commit $(json_field "$MANIFEST" commit), deployed $(json_field "$MANIFEST" deployed_at)"
    TARGET_MIGRATIONS="$(json_field "$MANIFEST" migration_count)"
else
    warn "no manifest for $TARGET — rolling back the images only"
    TARGET_MIGRATIONS=''
fi

# =========================================================================
# 2. Say what will change, including what will NOT
# =========================================================================
step "2/5  Plan"
LIVE_MIGRATIONS="$(db_exec psql -U "$DB_USER" -d "$DB_NAME" -tAc 'SELECT count(*) FROM migrations' 2>/dev/null | tr -d ' \r' || echo '?')"

say "  current release   ${CURRENT:-<unrecorded>}"
say "  rollback target   $TARGET"
say "  database          $LIVE_MIGRATIONS migrations applied (live)"
[ -n "$TARGET_MIGRATIONS" ] && say "                    $TARGET_MIGRATIONS expected by the target release"

SCHEMA_AHEAD=0
if [ -n "$TARGET_MIGRATIONS" ] && [ "$LIVE_MIGRATIONS" != '?' ] \
   && [ "$LIVE_MIGRATIONS" -gt "$TARGET_MIGRATIONS" ] 2>/dev/null; then
    SCHEMA_AHEAD=1
    say ""
    warn "the live schema is AHEAD of the target release by $((LIVE_MIGRATIONS - TARGET_MIGRATIONS)) migration(s)."
    warn "Rolling back the code will NOT remove them. The older image will run"
    warn "against the newer schema and simply ignore what it does not know about."
    if [ -z "$RESTORE_DB" ]; then
        warn "To go back to the data too, re-run with --restore-db <snapshot>."
        [ -f "$MANIFEST" ] && warn "Snapshot recorded for this release: $(json_field "$MANIFEST" pre_deploy_snapshot)"
    fi
fi

if [ -n "$RESTORE_DB" ]; then
    say ""
    warn "--restore-db: the live production database WILL BE OVERWRITTEN from"
    warn "  $RESTORE_DB"
fi

if [ "$ASSUME_YES" -ne 1 ]; then
    say ""
    printf '  Type "rollback" to proceed: '
    if [ -r /dev/tty ]; then read -r REPLY < /dev/tty; else read -r REPLY || REPLY=''; fi
    [ "$REPLY" = "rollback" ] || die "aborted — nothing was changed."
fi

# =========================================================================
# 3. Snapshot the CURRENT state before discarding it
# =========================================================================
# Rolling back is itself a change, and the state being left behind is the only
# evidence of what went wrong. Capture it before the containers are recreated.
step "3/5  Snapshot current state"
if sh "$REPO_ROOT/scripts/backup.sh" --stack prod --label "prerollback-${CURRENT:-unknown}" > "$REPO_ROOT/.rollback-snap.tmp" 2>&1; then
    ok "snapshot $(tail -1 "$REPO_ROOT/.rollback-snap.tmp")"
else
    warn "pre-rollback snapshot failed — continuing (see output below)"
    cat "$REPO_ROOT/.rollback-snap.tmp"
fi
rm -f "$REPO_ROOT/.rollback-snap.tmp"

# =========================================================================
# 4. Move the tags and recreate
# =========================================================================
step "4/5  Roll back images"
docker tag "sccit/app:prod-$TARGET" sccit/app:prod
docker tag "sccit/web:prod-$TARGET" sccit/web:prod
ok "sccit/app:prod and sccit/web:prod now point at $TARGET"

dc up -d --force-recreate app_prod nginx_prod queue_prod scheduler_prod
ok "services recreated"

if [ -n "$RESTORE_DB" ]; then
    sh "$REPO_ROOT/scripts/restore.sh" --stack prod --yes "$RESTORE_DB"
fi

WAITED=0
STATE='unknown'
while [ "$WAITED" -lt 90 ]; do
    STATE="$(docker inspect --format '{{.State.Health.Status}}' sccit_prod_app 2>/dev/null || echo unknown)"
    [ "$STATE" = 'healthy' ] && break
    WAITED=$((WAITED + 3))
    sleep 3
done
[ "$STATE" = 'healthy' ] && ok "application healthy after ${WAITED}s" || warn "still '$STATE' after ${WAITED}s"

# =========================================================================
# 5. Verify
# =========================================================================
step "5/5  Smoke test"
if sh "$REPO_ROOT/scripts/smoke.sh" --stack prod; then
    printf '%s\n' "$TARGET" > "$RELEASES/current"
    ok "rollback verified; releases/current is now $TARGET"
else
    fail "the rolled-back deployment did not pass its smoke test."
    say "  releases/current was left at '${CURRENT:-<unrecorded>}' — this rollback is NOT recorded as good."
    exit 1
fi

say ""
say "=============================================================="
say " Rolled back to $TARGET at $BASE_URL"
[ "$SCHEMA_AHEAD" -eq 1 ] && [ -z "$RESTORE_DB" ] && say " NOTE: the database schema is still ahead of this release."
say "=============================================================="

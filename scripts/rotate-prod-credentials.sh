#!/usr/bin/env sh
# =============================================================
#  SccIT — rotate the production PostgreSQL and Redis passwords.
#
#      scripts/rotate-prod-credentials.sh [--yes]
#
#  Why this exists: until 2026-10-20 production ran on the development
#  passwords documented in this (public) repository, and compose.prod.yaml fell
#  back to them silently. Rotation must be repeatable — after a suspected leak,
#  a departing administrator, or simply on a schedule — so it is a script, not
#  a list of commands to retype.
#
#  ── Sequence ─────────────────────────────────────────────────────────────
#    1  Preflight   production running, both env files present
#    2  Snapshot    backup.sh --verify (the data restore point)
#    3  Keep        copy both env files to backups/credentials/<ts>/ (mode 600)
#    4  Generate    two 40-character random passwords — never printed
#    5  PostgreSQL  ALTER ROLE via the container's local socket (stdin, so the
#                   value is in no command line and no shell history)
#    6  Env files   POSTGRES_PASSWORD/REDIS_PASSWORD (.env) and DB_PASSWORD/
#                   REDIS_PASSWORD (backend/.env.production)
#    7  Recreate    redis_prod first (requirepass is read at start), then every
#                   service running the app image (they re-cache config at boot)
#    8  Verify      Laravel reaches PostgreSQL and Redis; HTTP smoke test
#
#  No data is touched: the role keeps its name and privileges, and Redis keeps
#  its append-only file (sessions and queued jobs survive the restart).
#
#  If step 8 fails, the previous files are in backups/credentials/<ts>/; the
#  failure message prints the exact commands to put them back.
# =============================================================
set -eu

. "$(dirname "$0")/lib/stack.sh"

ASSUME_YES=0
while [ $# -gt 0 ]; do
    case "$1" in
        --yes|-y) ASSUME_YES=1; shift ;;
        -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
        *) die "unknown argument: $1" ;;
    esac
done

stack_select prod

ENV_ROOT="$REPO_ROOT/.env"
ENV_APP="$REPO_ROOT/backend/.env.production"
APP_SERVICES='app_prod queue_prod queue_ai_prod queue_bulk_prod scheduler_prod reverb_prod'

# ---------------------------------------------------------------------------
step "1/8  Preflight"
[ -f "$ENV_ROOT" ] || die "missing $ENV_ROOT"
[ -f "$ENV_APP" ] || die "missing $ENV_APP"
require_stack_running
ok "production stack running; env files present"

if [ "$ASSUME_YES" -ne 1 ]; then
    printf '  Rotate the PRODUCTION database and Redis passwords? Type "rotate": '
    if [ -r /dev/tty ]; then read -r REPLY < /dev/tty; else read -r REPLY || REPLY=''; fi
    [ "$REPLY" = "rotate" ] || die "aborted — nothing was changed."
fi

# ---------------------------------------------------------------------------
step "2/8  Snapshot"
SNAPSHOT="$(sh "$REPO_ROOT/scripts/backup.sh" --stack prod --label pre-credential-rotation --verify | tail -1)"
[ -n "$SNAPSHOT" ] && [ -f "$REPO_ROOT/$SNAPSHOT/verification.txt" ] || die "snapshot failed — nothing was changed."
grep -q 'VERIFIED' "$REPO_ROOT/$SNAPSHOT/verification.txt" || die "snapshot did not verify — nothing was changed."
ok "verified snapshot $SNAPSHOT"

# ---------------------------------------------------------------------------
step "3/8  Keep the current env files"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
KEEP_DIR="$REPO_ROOT/backups/credentials/$TS"
mkdir -p "$KEEP_DIR"
cp "$ENV_ROOT" "$KEEP_DIR/root.env"
cp "$ENV_APP" "$KEEP_DIR/backend.env.production"
chmod 600 "$KEEP_DIR"/* 2>/dev/null || true
ok "previous files kept in backups/credentials/$TS (gitignored, local only)"

# ---------------------------------------------------------------------------
step "4/8  Generate"
gen() { LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 40; }
NEW_DB="$(gen)"
NEW_REDIS="$(gen)"
[ "${#NEW_DB}" -eq 40 ] && [ "${#NEW_REDIS}" -eq 40 ] || die "password generation failed — nothing was changed."
ok "two 40-character passwords generated (not displayed)"

restore_hint() {
    say ""
    say "  To put the previous credentials back:"
    say "    cp backups/credentials/$TS/root.env .env"
    say "    cp backups/credentials/$TS/backend.env.production backend/.env.production"
    say "    # and, if step 5 completed, reset the role to the old DB_PASSWORD with ALTER ROLE"
    say "    docker compose -p sccit_prod -f compose.prod.yaml up -d --no-deps --force-recreate redis_prod $APP_SERVICES"
}

# ---------------------------------------------------------------------------
step "5/8  PostgreSQL role password"
printf "ALTER ROLE \"%s\" WITH PASSWORD '%s';\n" "$DB_USER" "$NEW_DB" \
    | db_exec psql -X -q -v ON_ERROR_STOP=1 -U "$DB_USER" -d "$DB_NAME" >/dev/null \
    || { restore_hint; die "ALTER ROLE failed — the env files were not changed."; }
ok "role $DB_USER updated (existing connections stay open until their services restart)"

# ---------------------------------------------------------------------------
step "6/8  Env files"
set_line() { # file key value quote
    tmp="$1.rotate.$$"
    awk -v k="$2" -v v="$3" -v q="$4" 'BEGIN{done=0} index($0, k"=")==1 {print k"="q v q; done=1; next} {print} END{if(!done) print k"="q v q}' "$1" > "$tmp"
    cat "$tmp" > "$1" && rm -f "$tmp"
}
set_line "$ENV_ROOT" POSTGRES_PASSWORD "$NEW_DB" ''
set_line "$ENV_ROOT" REDIS_PASSWORD "$NEW_REDIS" ''
set_line "$ENV_APP" DB_PASSWORD "$NEW_DB" "'"
set_line "$ENV_APP" REDIS_PASSWORD "$NEW_REDIS" "'"
ok "updated .env (POSTGRES_PASSWORD, REDIS_PASSWORD) and backend/.env.production (DB_PASSWORD, REDIS_PASSWORD)"

# ---------------------------------------------------------------------------
step "7/8  Recreate"
# shellcheck disable=SC2086  # intentional word-splitting of the service list
dc up -d --no-deps --force-recreate redis_prod || { restore_hint; die "redis_prod did not start"; }
WAITED=0
while [ "$WAITED" -lt 60 ]; do
    [ "$(docker inspect --format '{{.State.Health.Status}}' sccit_prod_redis 2>/dev/null)" = healthy ] && break
    WAITED=$((WAITED + 2)); sleep 2
done
ok "redis_prod healthy after ${WAITED}s"
# shellcheck disable=SC2086
dc up -d --no-deps --force-recreate $APP_SERVICES || { restore_hint; die "application services did not start"; }
WAITED=0
while [ "$WAITED" -lt 90 ]; do
    [ "$(docker inspect --format '{{.State.Health.Status}}' sccit_prod_app 2>/dev/null)" = healthy ] && break
    WAITED=$((WAITED + 3)); sleep 3
done
ok "application services recreated (app healthy after ${WAITED}s)"

# ---------------------------------------------------------------------------
step "8/8  Verify"
app_exec php artisan migrate:status >/dev/null 2>&1 || { restore_hint; die "Laravel cannot reach PostgreSQL with the new password."; }
ok "Laravel reaches PostgreSQL"
app_exec php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); Illuminate\Support\Facades\Redis::connection()->ping(); echo "ok";' 2>/dev/null | grep -q ok \
    || { restore_hint; die "Laravel cannot reach Redis with the new password."; }
ok "Laravel reaches Redis"
sh "$REPO_ROOT/scripts/smoke.sh" --stack prod || { restore_hint; die "smoke test failed after rotation."; }

say ""
say "Rotation complete. Restore point: $SNAPSHOT"
say "Previous env files: backups/credentials/$TS — delete them once you are satisfied (they hold the old values)."

#!/usr/bin/env sh
# =============================================================
#  Shared stack resolution for the SccIT operational scripts.
#
#  WHY THIS FILE EXISTS
#  Two Compose stacks run side by side on one machine, and the root .env sets
#  COMPOSE_PROJECT_NAME=sccit. By Compose's precedence that name overrides the
#  `name:` written inside compose.prod.yaml — so any production command that
#  forgets `-p sccit_prod` silently manages the DEVELOPMENT stack instead.
#  That has bitten this project before. Rather than repeat the flags in every
#  script (and eventually mistype them), every operational script sources this
#  file and calls dc()/app_exec()/db_exec(), which cannot forget them.
#
#  USAGE
#      . "$(dirname "$0")/lib/stack.sh"
#      stack_select prod          # or: dev
#      dc ps                      # -> docker compose -p sccit_prod -f compose.prod.yaml ps
#      app_exec php artisan migrate --force
#
#  After stack_select, these are exported for the calling script:
#      STACK          dev | prod
#      APP_SVC        app | app_prod
#      DB_SVC         postgres | postgres_prod
#      WEB_SVC        nginx | nginx_prod
#      BASE_URL       http://localhost:8080 | http://localhost:8081
#      STORAGE_VOLUME ''  (dev: bind-mounted) | sccit_prod_storage
#      DB_NAME / DB_USER
# =============================================================

# Repository root, regardless of where the caller was invoked from.
REPO_ROOT="$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
export REPO_ROOT

# Database credentials come from the root .env when present (dev and prod use
# the same PostgreSQL role); the defaults mirror compose.prod.yaml's own.
if [ -f "$REPO_ROOT/.env" ]; then
    DB_NAME="$(sed -n 's/^POSTGRES_DB=//p' "$REPO_ROOT/.env" | tr -d '"'"'"'\r' | head -1)"
    DB_USER="$(sed -n 's/^POSTGRES_USER=//p' "$REPO_ROOT/.env" | tr -d '"'"'"'\r' | head -1)"
fi
DB_NAME="${DB_NAME:-school_it_service_management}"
DB_USER="${DB_USER:-postgres}"
export DB_NAME DB_USER

# Set by stack_select; deliberately unquoted at the point of use in dc() so the
# flags word-split into separate arguments.
_COMPOSE_ARGS=''

stack_select() {
    STACK="${1:-dev}"

    case "$STACK" in
        dev)
            _COMPOSE_ARGS=''
            APP_SVC='app'
            DB_SVC='postgres'
            WEB_SVC='nginx'
            NODE_SVC='node'
            BASE_URL="${SCCIT_DEV_URL:-http://localhost:8080}"
            # Dev bind-mounts ./backend, so uploads live on the host filesystem
            # and are covered by the source tree — there is no named volume.
            STORAGE_VOLUME=''
            ;;
        prod)
            # The two flags that must never be omitted. See the header.
            _COMPOSE_ARGS='-p sccit_prod -f compose.prod.yaml'
            APP_SVC='app_prod'
            DB_SVC='postgres_prod'
            WEB_SVC='nginx_prod'
            NODE_SVC=''
            BASE_URL="${SCCIT_PROD_URL:-http://localhost:8081}"
            STORAGE_VOLUME='sccit_prod_storage'
            ;;
        *)
            echo "stack_select: unknown stack '$STACK' (expected 'dev' or 'prod')" >&2
            return 2
            ;;
    esac

    export STACK APP_SVC DB_SVC WEB_SVC NODE_SVC BASE_URL STORAGE_VOLUME
}

# docker compose, already carrying this stack's project/file flags.
dc() {
    # shellcheck disable=SC2086  # intentional word-splitting of the flag string
    ( cd "$REPO_ROOT" && docker compose $_COMPOSE_ARGS "$@" )
}

# Run a command in this stack's application container (no TTY, script-safe).
app_exec() {
    dc exec -T "$APP_SVC" "$@"
}

# Run a command in this stack's database container.
db_exec() {
    dc exec -T "$DB_SVC" "$@"
}

# Fail early with a readable message rather than a wall of Compose output.
require_stack_running() {
    if ! dc ps --status running --services 2>/dev/null | grep -qx "$APP_SVC"; then
        echo "The '$STACK' stack is not running (service '$APP_SVC' is down)." >&2
        echo "Start it first:  make $( [ "$STACK" = prod ] && echo prod-up || echo up )" >&2
        return 1
    fi
}

# ---- Console output -----------------------------------------------------
# Kept deliberately plain: these scripts are read in CI logs and in Git Bash on
# Windows, neither of which is guaranteed to render colour.
say()  { printf '%s\n' "$*"; }
step() { printf '\n== %s\n' "$*"; }
ok()   { printf '  OK    %s\n' "$*"; }
warn() { printf '  WARN  %s\n' "$*"; }
fail() { printf '  FAIL  %s\n' "$*" >&2; }
die()  { printf '\nERROR: %s\n' "$*" >&2; exit 1; }

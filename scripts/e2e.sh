#!/usr/bin/env sh
# =============================================================
#  SccIT — browser verification runner (WP-2.7d).
#
#      scripts/e2e.sh [--project e2e|a11y|smoke|all] [--grep PATTERN] [--no-seed]
#
#  Seeds the deterministic fixtures, then runs Playwright inside the `node`
#  container. Both halves matter: the suite reads its accounts and its QR code
#  from the manifest this script writes, so running `npx playwright test` by
#  hand fails with an explanatory error rather than a mystery.
#
#  ── Which target each project runs against ───────────────────────────────
#    e2e    dev  :8080   three-role auth, authorization boundaries, QR workflow
#    a11y   dev  :8080   axe regression against the committed baseline
#    smoke  prod :8081   does the DEPLOYED bundle boot in a browser
#
#  Fixtures are seeded into the dev stack only. The `smoke` project needs none —
#  and could not have them: `sccit:e2e-fixtures` refuses to run under
#  APP_ENV=production, because these accounts share one documented password.
# =============================================================
set -eu

. "$(dirname "$0")/lib/stack.sh"

PROJECT='e2e'
GREP=''
SEED=1

while [ $# -gt 0 ]; do
    case "$1" in
        --project) PROJECT="${2:?--project needs e2e|a11y|smoke|all}"; shift 2 ;;
        --grep) GREP="${2:?--grep needs a pattern}"; shift 2 ;;
        --no-seed) SEED=0; shift ;;
        -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
        *) die "unknown argument: $1" ;;
    esac
done

stack_select dev
require_stack_running || exit 1

ARTIFACTS="$REPO_ROOT/frontend/e2e/.artifacts"
mkdir -p "$ARTIFACTS"

# ---- 1. Fixtures ---------------------------------------------------------
# The `smoke` project runs against production and must not depend on seeded
# data, so seeding is skipped for it entirely.
if [ "$SEED" -eq 1 ] && [ "$PROJECT" != 'smoke' ]; then
    step "Seeding deterministic fixtures (dev)"
    # Written to a temp file first: a failed seed must not leave a truncated
    # manifest behind that the next run would parse as valid JSON.
    if app_exec php artisan sccit:e2e-fixtures --json > "$ARTIFACTS/fixtures.json.tmp"; then
        mv "$ARTIFACTS/fixtures.json.tmp" "$ARTIFACTS/fixtures.json"
        ok "frontend/e2e/.artifacts/fixtures.json"
    else
        rm -f "$ARTIFACTS/fixtures.json.tmp"
        die "fixture seeding failed — the browser suite has nothing to sign in as."
    fi
fi

# ---- 2. Preflight: is the target actually up? ---------------------------
# Playwright's failure for an unreachable target is a wall of navigation
# timeouts. One curl gives the real reason in one line.
if [ "$PROJECT" = 'smoke' ]; then
    TARGET="${SCCIT_PROD_URL:-http://localhost:8081}"
else
    TARGET="$BASE_URL"
fi
if ! curl -sS -o /dev/null --max-time 10 "$TARGET/up" 2>/dev/null; then
    die "$TARGET is not answering. Start the stack it belongs to first
       (dev: make up / sh scripts/up.sh — prod: make prod-up)."
fi
ok "target $TARGET is up"

# ---- 3. Run ------------------------------------------------------------
step "Playwright ($PROJECT)"

ARGS=''
[ "$PROJECT" = 'all' ] || ARGS="--project=$PROJECT"
[ -z "$GREP" ] || ARGS="$ARGS --grep=$GREP"

# CI=1 makes Playwright use its non-interactive reporter output; the browser is
# the Alpine Chromium baked into the image (see docker/node/Dockerfile).
dc exec -T -e CI=1 node sh -lc "cd /app && npx playwright test $ARGS"

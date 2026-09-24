#!/usr/bin/env sh
# =============================================================
#  SccIT — the quality gate (WP-2.7d).
#
#      scripts/gates.sh [--only backend|frontend] [--skip-build]
#                       [--with-e2e] [--with-a11y]
#
#  One command for the gate CLAUDE.md §6 describes, so "did you run the gates?"
#  has a single answer instead of nine remembered commands. Every gate runs
#  inside its container — never on the host — because the host has neither the
#  PHP extensions nor the Linux-native node_modules.
#
#  ── Why they run in this order ───────────────────────────────────────────
#  Cheapest and most localising first. A Pint failure is a formatting diff you
#  fix in a second; a Pest failure needs reading. Running the type-checker
#  before the test suites means a rename that broke a signature is reported as
#  a type error rather than as forty unrelated test failures.
#
#  ── Why Pest is never parallelised ───────────────────────────────────────
#  The suites share ONE test database. Two concurrent Pest runs fabricate
#  failures that look exactly like real regressions and cost an afternoon to
#  disbelieve. This script runs gates strictly one at a time for that reason.
#
#  Every gate is always attempted — an early failure does not abort the run —
#  so one invocation tells you everything that is broken. Exit status is the
#  number of failed gates.
# =============================================================
set -u

. "$(dirname "$0")/lib/stack.sh"

ONLY=''
SKIP_BUILD=0
WITH_E2E=0
WITH_A11Y=0

while [ $# -gt 0 ]; do
    case "$1" in
        --only) ONLY="${2:?--only needs backend|frontend}"; shift 2 ;;
        --skip-build) SKIP_BUILD=1; shift ;;
        --with-e2e) WITH_E2E=1; shift ;;
        --with-a11y) WITH_A11Y=1; shift ;;
        --stack)
            [ "${2:-dev}" = 'dev' ] || die "the gates run against the dev stack only:
       the production image installs with --no-dev, so Pint, PHPStan, Pest and
       the entire frontend toolchain are absent from it by design."
            shift 2 ;;
        -h|--help) sed -n '2,27p' "$0"; exit 0 ;;
        *) die "unknown argument: $1" ;;
    esac
done

stack_select dev
require_stack_running || exit 1

PASSED=0
FAILED=0
RESULTS=''

run_gate() { # run_gate <label> <command...>
    label="$1"; shift
    printf '\n-- %s\n' "$label"
    if "$@"; then
        PASSED=$((PASSED + 1))
        RESULTS="$RESULTS
  PASS  $label"
    else
        FAILED=$((FAILED + 1))
        RESULTS="$RESULTS
  FAIL  $label"
    fi
}

be() { dc exec -T app "$@"; }
fe() { dc exec -T node sh -lc "cd /app && $1"; }

say "=============================================================="
say " SccIT quality gate"
say "=============================================================="

if [ "$ONLY" != 'frontend' ]; then
    run_gate 'backend: Pint (format)'      be ./vendor/bin/pint --test
    run_gate 'backend: PHPStan (level 6)'  be ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G
    run_gate 'backend: Pest'               be ./vendor/bin/pest

    # `migrate:status` exits 0 even when migrations are pending, so the gate has
    # to read its output. A deployment whose schema is behind its code is the
    # exact failure this catches, and no other gate would notice it.
    printf '\n-- backend: migration status\n'
    if be php artisan migrate:status | tee /dev/stderr | grep -q 'Pending'; then
        FAILED=$((FAILED + 1)); RESULTS="$RESULTS
  FAIL  backend: migration status (pending migrations)"
    else
        PASSED=$((PASSED + 1)); RESULTS="$RESULTS
  PASS  backend: migration status"
    fi
fi

if [ "$ONLY" != 'backend' ]; then
    run_gate 'frontend: TypeScript'  fe 'npx tsc -b'
    run_gate 'frontend: ESLint'      fe 'npm run lint'
    run_gate 'frontend: Prettier'    fe 'npm run format:check'
    run_gate 'frontend: Vitest'      fe 'npm run test'
    [ "$SKIP_BUILD" -eq 1 ] || run_gate 'frontend: production build' fe 'npm run build'
fi

if [ "$WITH_E2E" -eq 1 ]; then
    run_gate 'browser: E2E (Playwright)' sh "$REPO_ROOT/scripts/e2e.sh" --project e2e
fi

if [ "$WITH_A11Y" -eq 1 ]; then
    run_gate 'browser: accessibility (axe)' sh "$REPO_ROOT/scripts/e2e.sh" --project a11y
fi

say ""
say "=============================================================="
say " Gate summary"
printf '%s\n' "$RESULTS"
say ""
say " $PASSED passed, $FAILED failed"
say "=============================================================="
exit "$FAILED"

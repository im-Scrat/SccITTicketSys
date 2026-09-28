#!/usr/bin/env sh
# =============================================================
#  SccIT — dependency-update procedure (dev stack only).
#
#      scripts/dep-update.sh -- composer update guzzlehttp/guzzle --with-dependencies
#      scripts/dep-update.sh -- composer require some/package
#      scripts/dep-update.sh --npm -- npm install some-package
#
#  ── Why this exists (root cause, not folklore) ────────────────────────────
#  On 2026-09-28, `composer update` (OD-1) rewrote real class-definition source
#  in backend/vendor/ — bind-mounted, so the change landed directly under the
#  RUNNING dev app container — while its php-fpm pool kept serving concurrent
#  requests. Dev's OPcache revalidates every request (validate_timestamps=1,
#  revalidate_freq=0) with tracing JIT enabled, and OPcache's compiled-code
#  cache AND JIT buffer are shared memory across every worker process in the
#  pool. A worker executing JIT-native code compiled from the OLD class
#  definition can end up running against memory another worker's invalidation
#  reallocates mid-request, once the NEW class differs in structure (different
#  properties, methods, opcodes) — not merely a different file on disk. Result:
#  592 SIGSEGV worker crashes / ~46 minutes, invisible to Pest/Pint/PHPStan
#  (opcache.enable_cli=0), first surfaced by nginx's healthcheck.
#
#  CONFIRMED, not assumed: `composer dump-autoload` — a real vendor/ file
#  rewrite (9573 classes remapped) under 600 concurrent requests — reproduced
#  ZERO crashes. Rewriting the autoload MAP alone is not enough; the trigger is
#  CODE-CONTENT changing under classes that may be actively JIT-executing.
#  `composer update`/`require`/`remove` (real package source swaps) IS in that
#  class. Confirmed absent from production BY ARCHITECTURE: the prod Dockerfile
#  installs into an image during `docker build`, never into a running
#  container, and opcache.validate_timestamps=0 there means a live prod worker
#  never revalidates at all — there is no window for this race to occur.
#
#  So the fix is not a config change (do not disable JIT; production already
#  runs JIT, at a LARGER buffer, safely, because of the two facts above) — it
#  is a lifecycle guarantee: no dev app/queue/scheduler/reverb worker may keep
#  running with code that predates a vendor swap. This script makes that
#  guarantee mechanical instead of remembered.
#
#  Laravel's queue/schedule workers have an INDEPENDENT, well-documented reason
#  for the same restart: a long-lived `queue:work`/`schedule:work` process keeps
#  its old PHP opcodes loaded for its entire lifetime and never re-reads
#  changed source at all, regardless of OPcache settings.
# =============================================================
set -eu

. "$(dirname "$0")/lib/stack.sh"

NPM=0
CMD_MARKER_SEEN=0
ARGS=''

while [ $# -gt 0 ]; do
    case "$1" in
        --npm) NPM=1; shift ;;
        --) CMD_MARKER_SEEN=1; shift; break ;;
        -h|--help) sed -n '2,15p' "$0"; exit 0 ;;
        *) die "unknown argument: $1 (the command must follow --)" ;;
    esac
done
[ "$CMD_MARKER_SEEN" -eq 1 ] || die "usage: $0 [--npm] -- <command...>"
[ $# -ge 1 ] || die "no command given after --"

stack_select dev
require_stack_running || exit 1

step "Pre-flight: dev app answering"
curl -fsS -o /dev/null "$BASE_URL/up" || die "$BASE_URL/up is not answering before the update — fix that first, this script cannot tell its failures apart from the update's."
ok "$BASE_URL/up (before)"

step "Running: $*"
if [ "$NPM" -eq 1 ]; then
    dc exec -T "$NODE_SVC" sh -lc "cd /app && $*"
else
    app_exec "$@"
fi
ok "command finished"

# ---- The mandatory step -----------------------------------------------
# Vendor is bind-mounted; the change above just happened under every process
# below. None of them may keep running on pre-change code.
step "Restarting every dev service that loaded PHP or JS from what just changed"
if [ "$NPM" -eq 1 ]; then
    dc restart "$NODE_SVC"
else
    dc restart "$APP_SVC" queue scheduler reverb
fi
ok "restarted"

step "Post-restart verification"
TRIES=0
until curl -fsS -o /dev/null "$BASE_URL/up" 2>/dev/null; do
    TRIES=$((TRIES + 1))
    [ "$TRIES" -lt 15 ] || die "$BASE_URL/up did not come back after the restart."
    sleep 2
done
ok "$BASE_URL/up (after)"

if [ "$NPM" -eq 0 ]; then
    STARTED_AT="$(dc inspect "$APP_SVC" -f '{{.State.StartedAt}}' 2>/dev/null || true)"
    N=0
    while [ "$N" -lt 20 ]; do curl -s -o /dev/null "$BASE_URL/api/health" & N=$((N + 1)); done
    wait
    CRASHES="$(dc logs --no-color --since "${STARTED_AT:-1s}" "$APP_SVC" 2>/dev/null | grep -c 'signal 11' || true)"
    [ "$CRASHES" -eq 0 ] || die "php-fpm crashed $CRASHES time(s) against the RESTARTED worker — this is a new problem, not the known pre-restart race. Do not proceed."
    ok "0 SIGSEGV against 20 concurrent requests post-restart"
fi

say ''
say 'Done. The dev stack is verified running the code just installed.'
say 'This does not touch the production image: production installs at `docker build`'
say 'time into an immutable layer and never revalidates a running container.'

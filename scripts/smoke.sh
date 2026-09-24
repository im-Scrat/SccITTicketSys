#!/usr/bin/env sh
# =============================================================
#  SccIT — deployment smoke test (WP-2.7d).
#
#      scripts/smoke.sh [--stack dev|prod] [--url URL] [--email E --password P]
#
#  Answers one question against a RUNNING target: "is this deployment actually
#  serving the application, or merely up?" Containers report healthy long before
#  that is true — a stale bundle, an unmigrated database, a dropped Redis
#  connection and a broken CSP all leave every healthcheck green.
#
#  It talks HTTP only. No container internals, no artisan, nothing that would
#  pass on a machine where the deployment itself is broken. That is deliberate:
#  it is the same surface a browser sees, so it can be pointed at any target.
#
#  Checks, in order of how early they fail:
#    1  /up                     framework health route (nginx -> php-fpm)
#    2  /api/health             database + Redis reachable FROM the app
#    3  /                       SPA document, with the built bundle referenced
#    4  the referenced JS asset  the bundle is really on disk and served
#    5  /sign-in                nginx SPA fallback for client-side routes
#    6  security headers        nosniff / frame / referrer / CSP on the document
#    7  POST /api/login (bad)   API + validation reachable, and not a 500
#    8  authenticated round-trip (only with --email/--password)
#
#  Exit status is the number of failed checks, so callers can branch on it.
# =============================================================
set -u

. "$(dirname "$0")/lib/stack.sh"

STACK_ARG='prod'
URL_OVERRIDE=''
EMAIL=''
PASSWORD=''

while [ $# -gt 0 ]; do
    case "$1" in
        --stack) STACK_ARG="${2:?}"; shift 2 ;;
        --url) URL_OVERRIDE="${2:?}"; shift 2 ;;
        --email) EMAIL="${2:?}"; shift 2 ;;
        --password) PASSWORD="${2:?}"; shift 2 ;;
        -h|--help) sed -n '2,26p' "$0"; exit 0 ;;
        *) die "unknown argument: $1" ;;
    esac
done

stack_select "$STACK_ARG"
BASE="${URL_OVERRIDE:-$BASE_URL}"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

PASS=0
FAIL=0
pass() { PASS=$((PASS + 1)); printf '  PASS  %s\n' "$1"; }
bad()  { FAIL=$((FAIL + 1)); printf '  FAIL  %s  (%s)\n' "$1" "$2"; }
expect() { # expect <label> <wanted> <got>
    if [ "$2" = "$3" ]; then pass "$1 -> $3"; else bad "$1" "expected $2, got $3"; fi
}

# Status code only; body lands in $TMP/body.
code() { curl -sS -o "$TMP/body" -w '%{http_code}' --max-time 20 "$@" 2>/dev/null || echo 000; }

say "=============================================================="
say " Smoke test — $BASE  (stack: $STACK)"
say "=============================================================="

# ---- 1. Framework health route ------------------------------------------
expect "GET /up" "200" "$(code "$BASE/up")"

# ---- 2. App-side dependency health --------------------------------------
HEALTH_CODE="$(code -H 'Accept: application/json' "$BASE/api/health")"
expect "GET /api/health" "200" "$HEALTH_CODE"
if [ "$HEALTH_CODE" = "200" ]; then
    for dep in database redis; do
        if grep -q "\"$dep\":\"ok\"" "$TMP/body"; then
            pass "  health: $dep ok"
        else
            bad "  health: $dep" "not ok"
        fi
    done
fi

# ---- 3/4. The SPA document and its bundle -------------------------------
# The failure this catches is the expensive one: a production image rebuilt
# without the frontend, or an index.html pointing at an asset hash that no
# longer exists. Both serve a 200 that renders a blank page.
DOC_CODE="$(code "$BASE/")"
expect "GET / (SPA document)" "200" "$DOC_CODE"
if [ "$DOC_CODE" = "200" ]; then
    cp "$TMP/body" "$TMP/index.html"
    if grep -q 'id="root"' "$TMP/index.html"; then
        pass "  document contains the SPA mount point"
    else
        bad "  document contains the SPA mount point" "no #root element"
    fi
    ASSET="$(sed -n 's/.*<script[^>]*src="\([^"]*\.js\)".*/\1/p' "$TMP/index.html" | head -1)"
    if [ -n "$ASSET" ]; then
        case "$ASSET" in
            /*) ASSET_URL="$BASE$ASSET" ;;
            *)  ASSET_URL="$BASE/$ASSET" ;;
        esac
        expect "  GET $ASSET" "200" "$(code "$ASSET_URL")"
    elif [ "$STACK" = 'prod' ]; then
        # A hashed <script src> only exists in a production build; its absence
        # on the production target means the bundle never made it into the image.
        bad "  document references a built bundle" "no script src found"
    else
        pass "  document references a dev module entry (dev stack)"
    fi
fi

# ---- 5. SPA fallback for a client-side route ----------------------------
expect "GET /sign-in (SPA fallback)" "200" "$(code "$BASE/sign-in")"

# ---- 6. Security headers on the document --------------------------------
curl -sS -o /dev/null -D "$TMP/headers" --max-time 20 "$BASE/" 2>/dev/null || true
check_header() { # check_header <name> <substring>
    if grep -iq "^$1:.*$2" "$TMP/headers"; then
        pass "  header $1"
    else
        bad "  header $1" "missing or unexpected"
    fi
}
check_header 'X-Content-Type-Options' 'nosniff'
check_header 'X-Frame-Options' 'DENY'
check_header 'Referrer-Policy' 'strict-origin'
check_header 'Content-Security-Policy' 'default-src'

# ---- 7. The API rejects bad credentials without a 500 -------------------
# A 500 here is the signature of an unmigrated or unreachable database, which
# every container healthcheck would still report as healthy.
LOGIN_BAD="$(code -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' \
    -H "Origin: $BASE" -H "Referer: $BASE/" \
    -d '{"email":"smoke-no-such-user@invalid.test","password":"not-a-real-password"}' \
    "$BASE/api/login")"
case "$LOGIN_BAD" in
    401|422) pass "POST /api/login (bad credentials) -> $LOGIN_BAD" ;;
    419)     pass "POST /api/login (bad credentials) -> 419 CSRF enforced" ;;
    *)       bad "POST /api/login (bad credentials)" "expected 401/419/422, got $LOGIN_BAD" ;;
esac

# ---- 8. Authenticated round-trip (opt-in) -------------------------------
# Sanctum's SPA flow: fetch the CSRF cookie, then send credentials with the
# XSRF header — the same sequence the browser performs.
if [ -n "$EMAIL" ] && [ -n "$PASSWORD" ]; then
    JAR="$TMP/jar"
    curl -sS -c "$JAR" -b "$JAR" -o /dev/null --max-time 20 \
        -H "Origin: $BASE" -H "Referer: $BASE/" "$BASE/sanctum/csrf-cookie" 2>/dev/null || true
    TOKEN="$(awk '/XSRF-TOKEN/{print $7}' "$JAR" 2>/dev/null | tail -1 | sed 's/%3D/=/g')"
    LOGIN="$(curl -sS -c "$JAR" -b "$JAR" -o "$TMP/body" -w '%{http_code}' --max-time 20 \
        -H 'Accept: application/json' -H 'Content-Type: application/json' \
        -H "X-XSRF-TOKEN: $TOKEN" -H "Origin: $BASE" -H "Referer: $BASE/" \
        -X POST "$BASE/api/login" -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\"}" 2>/dev/null || echo 000)"
    expect "POST /api/login ($EMAIL)" "200" "$LOGIN"
    if [ "$LOGIN" = "200" ]; then
        TOKEN="$(awk '/XSRF-TOKEN/{print $7}' "$JAR" | tail -1 | sed 's/%3D/=/g')"
        USER_CODE="$(curl -sS -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' --max-time 20 \
            -H 'Accept: application/json' -H "X-XSRF-TOKEN: $TOKEN" \
            -H "Origin: $BASE" -H "Referer: $BASE/" "$BASE/api/user" 2>/dev/null || echo 000)"
        expect "  GET /api/user (session established)" "200" "$USER_CODE"
        curl -sS -b "$JAR" -c "$JAR" -o /dev/null --max-time 20 \
            -H 'Accept: application/json' -H "X-XSRF-TOKEN: $TOKEN" \
            -H "Origin: $BASE" -H "Referer: $BASE/" -X POST "$BASE/api/logout" 2>/dev/null || true
    fi
fi

say ""
say "--------------------------------------------------------------"
say " Smoke: $PASS passed, $FAIL failed  ($BASE)"
say "--------------------------------------------------------------"
exit "$FAIL"

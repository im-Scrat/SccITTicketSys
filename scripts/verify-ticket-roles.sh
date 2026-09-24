#!/usr/bin/env bash
# Live three-role verification for Ticket Management (Phase 2.6, WP-K).
#
#   bash scripts/verify-ticket-roles.sh <base-url> <admin-email> <tech-email> <teacher-email> <password>
#
# Drives the real HTTP API through Sanctum's SPA cookie flow — the same path a
# browser takes — rather than asserting anything in-process. The point is to
# prove behaviour in a running environment, dev and production alike, because a
# green test suite says nothing about whether the deployed image behaves.
#
# What it proves, per role:
#   Teacher        can report; sees own tickets in full; sees another reporter's
#                  only as the redacted community card; is refused every
#                  /admin/tickets route.
#   Technician     is refused the community feed (403, not an empty list); sees
#                  only assigned work; CANNOT reach an unassigned ticket by its
#                  uuid — the IDOR case that a list filter alone would miss.
#   Administrator  sees every ticket and the administrative surface.

set -u

BASE="${1:?base url, e.g. http://localhost:8080}"
ADMIN_EMAIL="${2:?}"
TECH_EMAIL="${3:?}"
TEACHER_EMAIL="${4:?}"
PASSWORD="${5:?}"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

PASS=0
FAIL=0

ok()   { PASS=$((PASS+1)); printf '  PASS  %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL  %s  (%s)\n' "$1" "$2"; }
check() { # check <label> <expected> <actual>
  if [ "$2" = "$3" ]; then ok "$1 -> $3"; else bad "$1" "expected $2, got $3"; fi
}

# ---- Sanctum SPA login: csrf cookie, then credentials with the XSRF header ----
login() { # login <jar> <email>
  local jar="$1" email="$2" token
  rm -f "$jar"
  curl -sS -c "$jar" -b "$jar" -o /dev/null -H "Origin: $BASE" -H "Referer: $BASE/" "$BASE/sanctum/csrf-cookie"
  token="$(awk '/XSRF-TOKEN/{print $7}' "$jar" | tail -1 | sed 's/%3D/=/g')"
  curl -sS -c "$jar" -b "$jar" -o "$TMP/login.json" -w '%{http_code}' \
    -H 'Accept: application/json' -H 'Content-Type: application/json' \
    -H "X-XSRF-TOKEN: $token" -H "Origin: $BASE" -H "Referer: $BASE/" \
    -X POST "$BASE/api/login" \
    -d "{\"email\":\"$email\",\"password\":\"$PASSWORD\"}"
}

# Status code only.
code() { # code <jar> <method> <path> [body]
  local jar="$1" method="$2" path="$3" body="${4:-}" token
  token="$(awk '/XSRF-TOKEN/{print $7}' "$jar" | tail -1 | sed 's/%3D/=/g')"
  if [ -n "$body" ]; then
    curl -sS -b "$jar" -c "$jar" -o "$TMP/out.json" -w '%{http_code}' \
      -H 'Accept: application/json' -H 'Content-Type: application/json' \
      -H "X-XSRF-TOKEN: $token" -H "Origin: $BASE" -H "Referer: $BASE/" \
      -X "$method" "$BASE/api$path" -d "$body"
  else
    curl -sS -b "$jar" -c "$jar" -o "$TMP/out.json" -w '%{http_code}' \
      -H 'Accept: application/json' -H "X-XSRF-TOKEN: $token" \
      -H "Origin: $BASE" -H "Referer: $BASE/" \
      -X "$method" "$BASE/api$path"
  fi
}

# Body of the last `code` call.
body() { cat "$TMP/out.json"; }

echo "=============================================================="
echo " Ticket role verification — $BASE"
echo "=============================================================="

for role_pair in "admin:$ADMIN_EMAIL" "tech:$TECH_EMAIL" "teacher:$TEACHER_EMAIL"; do
  role="${role_pair%%:*}"; email="${role_pair#*:}"
  c="$(login "$TMP/$role.jar" "$email")"
  check "login $role ($email)" "200" "$c"
done

echo
echo "-- Teacher: report a fault ------------------------------------"
# Categories are addressed by slug ("value"), not by id — the create endpoint
# validates against ticket_categories.slug.
CAT="$(code "$TMP/teacher.jar" GET /tickets/options >/dev/null; body | python -c 'import sys,json; d=json.load(sys.stdin); print((d.get("data") or d).get("categories",[{}])[0].get("value",""))' 2>/dev/null)"
echo "  category id: ${CAT:-<none>}"

TICKET_PAYLOAD="{\"title\":\"Verification ticket A\",\"description\":\"Raised by the automated role verification for Phase 2.6 WP-K.\",\"category\":\"$CAT\"}"
c="$(code "$TMP/teacher.jar" POST /tickets "$TICKET_PAYLOAD")"
check "teacher POST /tickets" "201" "$c"
TICKET_A="$(body | python -c 'import sys,json; d=json.load(sys.stdin); print((d.get("data") or {}).get("id",""))' 2>/dev/null)"
echo "  ticket A uuid: ${TICKET_A:-<none>}"

# A second reporter's ticket, so the community projection has something to hide.
c="$(code "$TMP/admin.jar" POST /tickets "{\"title\":\"Verification ticket B\",\"description\":\"Second reporter, used to prove the redacted community projection.\",\"category\":\"$CAT\"}")"
TICKET_B="$(body | python -c 'import sys,json; d=json.load(sys.stdin); print((d.get("data") or {}).get("id",""))' 2>/dev/null)"
echo "  ticket B uuid: ${TICKET_B:-<none>} (admin-reported, status $c)"

echo
echo "-- Teacher: own vs community ----------------------------------"
check "teacher GET /tickets/mine"            "200" "$(code "$TMP/teacher.jar" GET /tickets/mine)"
check "teacher GET own ticket (full)"        "200" "$(code "$TMP/teacher.jar" GET "/tickets/$TICKET_A")"
check "teacher GET /tickets/feed"            "200" "$(code "$TMP/teacher.jar" GET /tickets/feed)"

if [ -n "${TICKET_B:-}" ]; then
  c="$(code "$TMP/teacher.jar" GET "/tickets/feed/$TICKET_B")"
  check "teacher GET another reporter's ticket via feed" "200" "$c"
  leaked="$(body | python -c "
import sys,json
raw=sys.stdin.read()
banned=['internal_notes','is_internal','assigned_technician','technician','sla','first_response_at','attachments','ai_']
found=[b for b in banned if b in raw]
print(','.join(found) if found else 'none')
" 2>/dev/null)"
  if [ "$leaked" = "none" ]; then ok "community projection leaks nothing (no technician/SLA/internal/attachment fields)"
  else bad "community projection redaction" "leaked: $leaked"; fi
fi

echo
echo "-- Teacher: administrative surface is closed ------------------"
check "teacher GET /admin/tickets"           "403" "$(code "$TMP/teacher.jar" GET /admin/tickets)"
check "teacher GET /admin/tickets/dashboard" "403" "$(code "$TMP/teacher.jar" GET /admin/tickets/dashboard)"
check "teacher POST assign"                  "403" "$(code "$TMP/teacher.jar" POST "/admin/tickets/$TICKET_A/assign" '{"technician":"x"}')"

echo
echo "-- Technician: queue only, feed refused -----------------------"
check "technician GET /tickets/feed (403, not empty)" "403" "$(code "$TMP/tech.jar" GET /tickets/feed)"
check "technician GET /tickets/assigned"             "200" "$(code "$TMP/tech.jar" GET /tickets/assigned)"
check "technician GET /admin/tickets"                "403" "$(code "$TMP/tech.jar" GET /admin/tickets)"

echo
echo "-- IDOR: technician reaching an UNASSIGNED ticket by uuid -----"
c="$(code "$TMP/tech.jar" GET "/tickets/$TICKET_A")"
if [ "$c" = "403" ] || [ "$c" = "404" ]; then ok "technician GET unassigned ticket by uuid -> $c"
else bad "technician GET unassigned ticket by uuid" "expected 403/404, got $c"; fi

c="$(code "$TMP/tech.jar" GET "/tickets/assigned/$TICKET_A")"
if [ "$c" = "403" ] || [ "$c" = "404" ]; then ok "technician GET unassigned via queue detail -> $c"
else bad "technician GET unassigned via queue detail" "expected 403/404, got $c"; fi

echo
echo "-- Administrator: full oversight ------------------------------"
check "admin GET /admin/tickets"           "200" "$(code "$TMP/admin.jar" GET /admin/tickets)"
check "admin GET /admin/tickets/dashboard" "200" "$(code "$TMP/admin.jar" GET /admin/tickets/dashboard)"
check "admin GET any ticket by uuid"       "200" "$(code "$TMP/admin.jar" GET "/admin/tickets/$TICKET_A")"

echo
echo "-- Assignment, then technician access follows it --------------"
TECH_UUID="$(code "$TMP/admin.jar" GET "/admin/users?search=verify.tech" >/dev/null; body | python -c 'import sys,json; d=json.load(sys.stdin); r=(d.get("data") or []); print(r[0]["id"] if r else "")' 2>/dev/null)"
if [ -n "$TECH_UUID" ]; then
  check "admin assigns ticket A to technician" "200" "$(code "$TMP/admin.jar" POST "/admin/tickets/$TICKET_A/assign" "{\"technician\":\"$TECH_UUID\"}")"
  check "technician now sees the assigned ticket" "200" "$(code "$TMP/tech.jar" GET "/tickets/assigned/$TICKET_A")"
  check "technician still refused the feed"       "403" "$(code "$TMP/tech.jar" GET /tickets/feed)"
else
  bad "resolve technician uuid" "admin user search returned nothing"
fi

echo
echo "-- Security headers on an API response ------------------------"
H="$(curl -sS -D - -o /dev/null -b "$TMP/admin.jar" -H 'Accept: application/json' "$BASE/api/user")"
echo "$H" | grep -qi 'x-content-type-options: nosniff' && ok "nosniff present" || bad "nosniff" "missing"
echo "$H" | grep -qi 'content-security-policy' && ok "CSP present on API response" || bad "CSP" "missing"

echo
echo "=============================================================="
printf ' RESULT: %d passed, %d failed\n' "$PASS" "$FAIL"
echo "=============================================================="
[ "$FAIL" -eq 0 ]

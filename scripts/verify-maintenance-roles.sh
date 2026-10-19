#!/usr/bin/env bash
# Live three-role verification for the Maintenance domain (WP-2.6).
#
#   bash scripts/verify-maintenance-roles.sh <base-url> <admin-email> <tech-email> <teacher-email> <password>
#
# Drives the real HTTP API through Sanctum's SPA cookie flow — the same path a
# browser takes — rather than asserting anything in-process. The point is to
# prove behaviour in a running environment, dev and production alike, because a
# green test suite says nothing about whether the deployed image behaves.
#
# What it proves, per role:
#   Teacher        is refused every maintenance route with 403 — not an empty
#                  list. A Teacher holds no `maintenance.*` permission at all
#                  (SRS §8.4), so the module is genuinely closed to them.
#   Technician     can open their own work and start it; CANNOT reach another
#                  technician's record by its uuid — the IDOR case a list filter
#                  alone would miss (FR-MNT-011); is refused the administrative
#                  directory, the dashboard and reassignment.
#   Administrator  sees the whole estate and the administrative surface.
#
# Also proves the rules that are easy to regress:
#   - completion is blocked for corrective work with no evidence, and for any
#     record with no account of what was done (FR-MNT-003/010);
#   - an illegal transition is a 422, never a silent no-op;
#   - a client-supplied `status` on create is ignored — every record opens
#     `scheduled` however the payload asks.
#
# An environment with no PC units cannot exercise the record-level rules. That
# is reported as a SKIP rather than a wall of failures, because it describes
# missing data and not broken code.

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
SKIPPED=0

ok()   { PASS=$((PASS+1)); printf '  PASS  %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL  %s  (%s)\n' "$1" "$2"; }
skip() { SKIPPED=$((SKIPPED+1)); printf '  SKIP  %s\n' "$1"; }
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

code() { # code <jar> <method> <path> [body]
  local jar="$1" method="$2" path="$3" payload="${4:-}" token
  token="$(awk '/XSRF-TOKEN/{print $7}' "$jar" | tail -1 | sed 's/%3D/=/g')"
  if [ -n "$payload" ]; then
    curl -sS -b "$jar" -c "$jar" -o "$TMP/out.json" -w '%{http_code}' \
      -H 'Accept: application/json' -H 'Content-Type: application/json' \
      -H "X-XSRF-TOKEN: $token" -H "Origin: $BASE" -H "Referer: $BASE/" \
      -X "$method" "$BASE/api$path" -d "$payload"
  else
    curl -sS -b "$jar" -c "$jar" -o "$TMP/out.json" -w '%{http_code}' \
      -H 'Accept: application/json' -H "X-XSRF-TOKEN: $token" \
      -H "Origin: $BASE" -H "Referer: $BASE/" \
      -X "$method" "$BASE/api$path"
  fi
}

body() { cat "$TMP/out.json"; }

field() { # field <dotted.path>   (reads the last response body on stdin)
  python -c "
import sys, json
try:
    d = json.load(sys.stdin)
except Exception:
    print(''); sys.exit()
for key in '$1'.split('.'):
    if isinstance(d, list):
        d = d[int(key)] if key.isdigit() and int(key) < len(d) else None
    elif isinstance(d, dict):
        d = d.get(key)
    else:
        d = None
    if d is None:
        print(''); sys.exit()
print(d)
" 2>/dev/null
}

echo "=============================================================="
echo " Maintenance role verification — $BASE"
echo "=============================================================="

for role_pair in "admin:$ADMIN_EMAIL" "tech:$TECH_EMAIL" "teacher:$TEACHER_EMAIL"; do
  role="${role_pair%%:*}"; email="${role_pair#*:}"
  c="$(login "$TMP/$role.jar" "$email")"
  check "login $role ($email)" "200" "$c"
done

echo
echo "-- Teacher: the module is closed, with 403 not an empty list ---"
check "teacher GET /maintenance"                 "403" "$(code "$TMP/teacher.jar" GET /maintenance)"
check "teacher GET /maintenance/history"         "403" "$(code "$TMP/teacher.jar" GET /maintenance/history)"
check "teacher GET /maintenance/scheduled"       "403" "$(code "$TMP/teacher.jar" GET /maintenance/scheduled)"
check "teacher GET /maintenance/options"         "403" "$(code "$TMP/teacher.jar" GET /maintenance/options)"
check "teacher GET /admin/maintenance"           "403" "$(code "$TMP/teacher.jar" GET /admin/maintenance)"
check "teacher GET /admin/maintenance/dashboard" "403" "$(code "$TMP/teacher.jar" GET /admin/maintenance/dashboard)"

echo
echo "-- Technician: open corrective work on a real machine ---------"
REC_A=""
REC_B=""
ESTATE=1

code "$TMP/tech.jar" GET "/lookups/pc-units" >/dev/null
PC="$(body | field 'data.0.id')"

if [ -z "$PC" ]; then
  ESTATE=0
  skip "no selectable PC units here — record-level checks skipped"
else
  echo "  pc unit uuid: $PC"

  # A client-supplied status must be ignored: every record opens `scheduled`.
  PAYLOAD="{\"title\":\"Verification corrective repair\",\"type\":\"corrective\",\"pc_unit\":\"$PC\",\"status\":\"completed\"}"
  c="$(code "$TMP/tech.jar" POST /maintenance "$PAYLOAD")"
  check "technician POST /maintenance" "201" "$c"
  REC_A="$(body | field 'data.id')"
  OPENED_AS="$(body | field 'data.status.value')"
  echo "  record A uuid: ${REC_A:-<none>}"
  check "client-supplied status ignored on create" "scheduled" "${OPENED_AS:-<none>}"

  # A second owner's record, so the row scope has something to refuse.
  c="$(code "$TMP/admin.jar" POST /maintenance "{\"title\":\"Verification admin-owned round\",\"type\":\"preventive\",\"pc_unit\":\"$PC\"}")"
  REC_B="$(body | field 'data.id')"
  echo "  record B uuid: ${REC_B:-<none>} (admin-owned, status $c)"
fi

echo
echo "-- Technician: row scope, in the list AND by identifier --------"
check "technician GET /maintenance" "200" "$(code "$TMP/tech.jar" GET /maintenance)"

if [ -n "$REC_A" ]; then
  check "technician GET own record" "200" "$(code "$TMP/tech.jar" GET "/maintenance/$REC_A")"
fi

if [ -n "$REC_B" ]; then
  # The IDOR case. A list filter alone would pass while this fails.
  check "technician GET another owner's record by uuid" "403" "$(code "$TMP/tech.jar" GET "/maintenance/$REC_B")"
  check "technician GET another owner's audit trail"    "403" "$(code "$TMP/tech.jar" GET "/maintenance/$REC_B/audit")"

  listed="$(code "$TMP/tech.jar" GET /maintenance >/dev/null; body | python -c "
import sys, json
d = json.load(sys.stdin)
print('yes' if any(r['id'] == '$REC_B' for r in d.get('data', [])) else 'no')
" 2>/dev/null)"
  check "another owner's record absent from the technician list" "no" "${listed:-?}"
fi

echo
echo "-- Technician: refused the administrative surface --------------"
check "technician GET /admin/maintenance"           "403" "$(code "$TMP/tech.jar" GET /admin/maintenance)"
check "technician GET /admin/maintenance/dashboard" "403" "$(code "$TMP/tech.jar" GET /admin/maintenance/dashboard)"

if [ -n "$REC_A" ]; then
  reassign_payload='{"technician":"00000000-0000-0000-0000-000000000000"}'
  check "technician POST reassign (administrator only)" "403" \
    "$(code "$TMP/tech.jar" POST "/maintenance/$REC_A/reassign" "$reassign_payload")"
fi

if [ -n "$REC_A" ]; then
  echo
  echo "-- The completion gates ---------------------------------------"
  check "technician start work" "200" \
    "$(code "$TMP/tech.jar" PUT "/maintenance/$REC_A/status" '{"status":"in_progress"}')"

  # Corrective work with no evidence must be refused (FR-MNT-010).
  check "complete corrective with no evidence" "422" \
    "$(code "$TMP/tech.jar" PUT "/maintenance/$REC_A/status" '{"status":"completed","resolution":"Swapped the PSU."}')"

  # A resolution is required whatever the type (FR-MNT-003).
  check "complete with no account of the work" "422" \
    "$(code "$TMP/tech.jar" PUT "/maintenance/$REC_A/status" '{"status":"completed"}')"

  # An illegal transition is a 422, never a silent no-op.
  check "illegal transition in_progress -> scheduled" "422" \
    "$(code "$TMP/tech.jar" PUT "/maintenance/$REC_A/status" '{"status":"scheduled"}')"
fi

echo
echo "-- Administrator: the estate ----------------------------------"
check "admin GET /admin/maintenance"           "200" "$(code "$TMP/admin.jar" GET /admin/maintenance)"
check "admin GET /admin/maintenance/dashboard" "200" "$(code "$TMP/admin.jar" GET /admin/maintenance/dashboard)"

if [ -n "$REC_A" ] && [ -n "$REC_B" ]; then
  check "admin GET a technician's record" "200" "$(code "$TMP/admin.jar" GET "/maintenance/$REC_A")"

  # The directory really is the estate: both owners' records are in it.
  both="$(code "$TMP/admin.jar" GET /admin/maintenance >/dev/null; body | python -c "
import sys, json
d = json.load(sys.stdin)
ids = {r['id'] for r in d.get('data', [])}
print('yes' if '$REC_A' in ids and '$REC_B' in ids else 'no')
" 2>/dev/null)"
  check "administrator directory spans both owners" "yes" "${both:-?}"
fi

echo
echo "-- Equipment reached only through the work ---------------------"
check "technician GET /lookups/pc-units (labels only)" "200" "$(code "$TMP/tech.jar" GET /lookups/pc-units)"
check "technician GET /admin/pc-units (the register)"  "403" "$(code "$TMP/tech.jar" GET /admin/pc-units)"
check "technician GET /admin/assets"                   "403" "$(code "$TMP/tech.jar" GET /admin/assets)"

echo
echo "=============================================================="
if [ "$ESTATE" -eq 0 ]; then
  echo " NOTE: no PC units in this environment, so the record-level rules"
  echo "       (row scope, completion gates) were not exercised here."
fi
printf ' %d passed, %d failed, %d skipped\n' "$PASS" "$FAIL" "$SKIPPED"
echo "=============================================================="
[ "$FAIL" -eq 0 ]

#!/usr/bin/env sh
# RETIRED 2026-10-10. This ran `migrate:fresh` (drop every table) in the
# development stack, which the owner retired. There is deliberately no
# production equivalent: on production that command is data loss.
#   Tests get a fresh database automatically: sh scripts/gates.sh
# See docs/PRODUCTION_DEVELOPER_FILE_MAP.md.
echo "scripts/fresh.sh: retired with the development stack on 2026-10-10." >&2
echo "  There is no production equivalent by design. Tests: sh scripts/gates.sh" >&2
exit 1

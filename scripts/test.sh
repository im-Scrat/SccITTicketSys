#!/usr/bin/env sh
# RETIRED 2026-10-10. This ran Pest and Vitest inside the development stack's
# containers, which the owner retired. The same suites — plus Pint, PHPStan,
# tsc, ESLint, Prettier and the production build — run in a disposable,
# isolated environment:
#   sh scripts/gates.sh                   (or --only backend / --only frontend)
# See docs/PRODUCTION_DEVELOPER_FILE_MAP.md.
echo "scripts/test.sh: retired with the development stack on 2026-10-10." >&2
echo "  Run the tests with: sh scripts/gates.sh   (isolated; --only backend|frontend)" >&2
exit 1

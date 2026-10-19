#!/usr/bin/env sh
# RETIRED 2026-10-10. This started the development stack (`docker compose up -d`
# on compose.yaml, :8080), which the owner retired. Kept as a stub so an old
# habit fails loudly instead of recreating that stack on its kept volumes.
#   Production status:  docker compose -p sccit_prod -f compose.prod.yaml ps
#   Ship a change:      sh scripts/deploy-prod.sh
# See docs/PRODUCTION_DEVELOPER_FILE_MAP.md.
echo "scripts/up.sh: the development stack was retired on 2026-10-10." >&2
echo "  Production (:8081): docker compose -p sccit_prod -f compose.prod.yaml ps | sh scripts/deploy-prod.sh" >&2
exit 1

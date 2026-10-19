#!/usr/bin/env sh
# RETIRED 2026-10-10. This stopped the development stack (`docker compose down`
# on compose.yaml) — and with --volumes would have deleted the dev volumes the
# owner chose to keep. Kept as a stub so it can do neither.
#   Stop production (keeps its data):  docker compose -p sccit_prod -f compose.prod.yaml down
#   Never add -v / --volumes: that deletes the production database.
# See docs/PRODUCTION_DEVELOPER_FILE_MAP.md.
echo "scripts/down.sh: the development stack was retired on 2026-10-10." >&2
echo "  To stop production (keeps data): docker compose -p sccit_prod -f compose.prod.yaml down" >&2
exit 1

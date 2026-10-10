#!/usr/bin/env sh
# RETIRED 2026-10-10. This ran migrations in the development stack, which the
# owner retired. Production migrations run inside sh scripts/deploy-prod.sh,
# after a restore-verified snapshot, so a migration is never the first change
# without a way back.
#   Read-only check: docker compose -p sccit_prod -f compose.prod.yaml exec -T app_prod php artisan migrate:status
# See docs/PRODUCTION_DEVELOPER_FILE_MAP.md.
echo "scripts/migrate.sh: retired with the development stack on 2026-10-10." >&2
echo "  Production migrations run in: sh scripts/deploy-prod.sh" >&2
exit 1

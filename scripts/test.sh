#!/usr/bin/env sh
# Run backend (Pest) and frontend (Vitest) test suites in the containers.
set -e
cd "$(dirname "$0")/.."
echo "== Backend (Pest) =="
docker compose exec -T app php vendor/bin/pest "$@"
echo ""
echo "== Frontend (Vitest) =="
docker compose exec -T node npm run test

#!/usr/bin/env sh
# Start the full stack (detached) and show status.
set -e
cd "$(dirname "$0")/.."
docker compose up -d "$@"
docker compose ps

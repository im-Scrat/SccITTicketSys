#!/usr/bin/env sh
# Stop the stack. Pass --volumes to also remove named volumes.
set -e
cd "$(dirname "$0")/.."
docker compose down "$@"

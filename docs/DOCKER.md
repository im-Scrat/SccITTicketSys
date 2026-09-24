# Docker Guide

The whole stack is defined in [`compose.yaml`](../compose.yaml). All resources
are prefixed `sccit_` and live on a dedicated `sccit_network`, isolated from
other projects on the machine.

## Services

| Service | Container | Image | Host port | Role |
| ------- | --------- | ----- | --------- | ---- |
| `postgres` | `sccit_postgres` | `pgvector/pgvector:pg17` | `127.0.0.1:5432` | PostgreSQL 17 (+ pgvector) |
| `redis` | `sccit_redis` | `redis:7-alpine` | `127.0.0.1:6379` | Cache / sessions / queues |
| `mailpit` | `sccit_mailpit` | `axllent/mailpit` | `127.0.0.1:8025` (UI) | Local mail catcher |
| `app` | `sccit_app` | `sccit/app:dev` (PHP 8.4-FPM) | — | Laravel (php-fpm) |
| `nginx` | `sccit_nginx` | `nginx:1.27-alpine` | `127.0.0.1:8080` | Single-origin entrypoint |
| `queue` | `sccit_queue` | `sccit/app:dev` | — | `queue:work` worker |
| `scheduler` | `sccit_scheduler` | `sccit/app:dev` | — | `schedule:work` |
| `node` | `sccit_node` | `sccit/node:dev` (Node 22) | — | Vite dev server (HMR) |

## Volumes (named)

`sccit_pgdata`, `sccit_redisdata`, `sccit_mailpitdata`, `sccit_node_modules`.

> `sccit_node_modules` keeps the container's Linux `node_modules` separate from
> the Windows host's — their native binaries differ.

## Images

- **PHP** ([`docker/php/Dockerfile`](../docker/php/Dockerfile)) — multi-stage
  (`base` → `development` / `production`). Runs as non-root `appuser`.
  Extensions: pdo_pgsql, pgsql, redis, bcmath, intl, zip, gd, pcntl, opcache.
  FPM healthcheck via `ping.path`.
- **Node** ([`docker/node/Dockerfile`](../docker/node/Dockerfile)) — `development`
  (Vite + HMR) and a `build`→`production` (static via Nginx) seam.

## Single-origin routing

Nginx ([`docker/nginx/default.conf`](../docker/nginx/default.conf)) serves one
origin on `:8080`:

- `/api`, `/sanctum`, `/up`, `/storage` → Laravel (`app:9000`)
- everything else → Vite dev server (`node:5173`), including the HMR WebSocket

No CORS; Sanctum cookies are first-party. The `node` upstream is resolved at
request time (Docker DNS) so Nginx starts even if the frontend isn't up yet.

## Common commands

```bash
docker compose up -d --build      # build + start
docker compose ps                 # status / health
docker compose logs -f app        # tail one service
docker compose exec app bash      # shell into Laravel
docker compose down               # stop
docker compose down -v            # stop + delete volumes (DESTROYS DB DATA)
```

## Healthchecks

`postgres` (pg_isready), `redis` (auth ping), `nginx` (`/up`), `app` (FPM ping).
`queue`/`scheduler` don't run php-fpm, so the inherited image healthcheck is
disabled for them. `mailpit` ships its own.

## Production seam

This is a production-grade **development** environment. The production stack
exists: `compose.prod.yaml` builds the `production` targets (baked code +
`npm run build` static assets, no dev server / HMR) and runs on `:8081`.

Every production command needs `-p sccit_prod`, because the root `.env`'s
`COMPOSE_PROJECT_NAME=sccit` overrides the `name:` inside the file and would
otherwise place the stack in the dev project. The scripts in `scripts/` carry
those flags for you — see [OPERATIONS.md](OPERATIONS.md) for deployment,
snapshots, release retention and rollback.

## Browser testing in the `node` image

The `development` stage bakes Alpine's Chromium plus its runtime libraries
(`nss`, `freetype`, `harfbuzz`, fonts) for the Playwright and axe suites.

It is baked rather than installed into the running container, which is how the
browser arrived for the Phase 2.4–2.6b verification passes: that browser lived
in a container's writable layer, vanished on the next rebuild, and left results
nobody could reproduce without an undocumented `apk add`.

Alpine's Chromium is used instead of Playwright's own download because this is a
musl image and Playwright ships glibc builds — hence
`PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1` and `CHROMIUM_PATH=/usr/bin/chromium`, both
set in the image and read by `frontend/playwright.config.ts`.

After changing `frontend/package.json`, refresh the named `node_modules` volume —
Docker only seeds an empty volume, so an image rebuild alone will not do it:

```bash
docker compose exec -T node sh -lc 'cd /app && npm install'
# or, to rebuild the image and reseed:
docker compose up -d --build --force-recreate node
```

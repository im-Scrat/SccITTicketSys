# Docker Guide

The application runs as the **production stack**, defined in
[`compose.prod.yaml`](../compose.prod.yaml) and served on `http://localhost:8081`.
It is the only stack: the development stack ([`compose.yaml`](../compose.yaml),
`:8080`) was **retired on 2026-10-10** — see the last section.

Every command needs `-p sccit_prod`, because the root `.env`'s
`COMPOSE_PROJECT_NAME=sccit` overrides the `name:` inside the file. The scripts
in `scripts/` carry those flags for you ([`scripts/lib/stack.sh`](../scripts/lib/stack.sh));
see [OPERATIONS.md](OPERATIONS.md) for deployment, snapshots and rollback.

## Services

| Service | Container | Image | Host port | Role |
| ------- | --------- | ----- | --------- | ---- |
| `postgres_prod` | `sccit_prod_postgres` | `pgvector/pgvector:pg17` | — | PostgreSQL 17 (+ pgvector) |
| `redis_prod` | `sccit_prod_redis` | `redis:7-alpine` | — | Cache / sessions / queues |
| `mailpit_prod` | `sccit_prod_mailpit` | `axllent/mailpit` | `127.0.0.1:8026` (UI) | Local mail catcher |
| `app_prod` | `sccit_prod_app` | `sccit/app:prod` | — | Laravel (php-fpm), code baked in |
| `nginx_prod` | `sccit_prod_nginx` | `sccit/web:prod` | `127.0.0.1:8081` | Static SPA + reverse proxy |
| `queue_prod` | `sccit_prod_queue` | `sccit/app:prod` | — | `queue:work` (realtime, notifications, default) |
| `queue_ai_prod` | `sccit_prod-queue_ai_prod-N` | `sccit/app:prod` | — | `queue:work --queue=ai`, `AI_WORKERS` replicas (default 3) |
| `queue_bulk_prod` | `sccit_prod_queue_bulk` | `sccit/app:prod` | — | `queue:work --queue=bulk` — report documents, asset imports (512 MB, 170 s) |
| `scheduler_prod` | `sccit_prod_scheduler` | `sccit/app:prod` | — | `schedule:work` |
| `reverb_prod` | `sccit_prod_reverb` | `sccit/app:prod` | — (internal 6001) | WebSockets (Reverb) |

All services share the `sccit_prod_network` network.

## Volumes (named)

`sccit_prod_pgdata` (database), `sccit_prod_redisdata`, `sccit_prod_storage`
(public disk — `/var/www/html/storage/app/public`; read-only in `nginx_prod`, which
serves it at `/storage/`), `sccit_prod_private` (private disk —
`/var/www/html/storage/app/private`: attachments, served only through the
authorizing controllers; mounted on `app_prod`, `queue_prod`, `queue_ai_prod`,
`queue_bulk_prod` and `scheduler_prod`, **never** on `nginx_prod`), `sccit_prod_mailpitdata`.

> `sccit_prod_private` has been live since 2026-10-11. Before it, private files
> lived in each container's own layer and would not have survived a recreate
> (none had been uploaded). See [OPERATIONS.md](OPERATIONS.md) §2.

> Never run `down -v` against this project: it deletes the production database.
> Three of these volumes carry the label `com.docker.compose.project=sccit`
> (created before `-p sccit_prod` was enforced), so never select volumes by label
> either — name them exactly.

## Images

- **PHP** ([`docker/php/Dockerfile`](../docker/php/Dockerfile), `production`
  target) — `backend/` copied in, `composer install --no-dev`, production
  `php.prod.ini` and [`prod-entrypoint.sh`](../docker/php/prod-entrypoint.sh)
  (config/route/event caches). Non-root `appuser`. No Pest, Pint or PHPStan.
- **Web** ([`docker/nginx/Dockerfile.prod`](../docker/nginx/Dockerfile.prod)) —
  a `node:22-alpine` stage runs `npm ci` and `npm run build` on `frontend/`; the
  `nginx:1.27-alpine` stage serves the result from `/usr/share/nginx/html` with
  [`prod.conf`](../docker/nginx/prod.conf) and `security-headers.prod.conf`.

Code is **baked in**: a source change reaches `:8081` only through a rebuild —
use `sh scripts/deploy-prod.sh`, which also tags each build for rollback.

## Single-origin routing ([`docker/nginx/prod.conf`](../docker/nginx/prod.conf))

- `/api`, `/sanctum`, `/up`, `/broadcasting` → Laravel (php-fpm in `app_prod`)
- `/reverb/app/` → `reverb_prod:6001` (WebSocket)
- `/storage/`, `/assets/` → static files
- everything else → the SPA (`index.html` fallback)

## Common commands

```bash
docker compose -p sccit_prod -f compose.prod.yaml ps                  # status / health
docker compose -p sccit_prod -f compose.prod.yaml logs -f app_prod    # tail one service
docker compose -p sccit_prod -f compose.prod.yaml exec app_prod sh    # shell into Laravel
docker compose -p sccit_prod -f compose.prod.yaml down                # stop (keeps data volumes)
sh scripts/deploy-prod.sh                                             # rebuild + redeploy a change
```

## Healthchecks

`postgres_prod` (`pg_isready`), `redis_prod` (auth ping), `nginx_prod` (`/up`),
`app_prod` (FPM ping), `reverb_prod` (socket on 6001), `mailpit_prod` (its own).
The queue, AI and scheduler workers do not run php-fpm, so the inherited image
healthcheck is disabled for them.

## The retired development stack

`compose.yaml` defined a bind-mounted development stack on `:8080` (`sccit_app`,
`sccit_nginx`, `sccit_node` with Vite HMR, `sccit_postgres` on host port 5432,
…). On 2026-10-10 its containers, `sccit_network` and the `sccit/app:dev` /
`sccit/node:dev` images were removed at the owner's request; its volumes
(`sccit_pgdata`, `sccit_redisdata`, `sccit_mailpitdata`, `sccit_node_modules`)
were kept. Every service in `compose.yaml` now carries `profiles: ["dev"]`, so a
bare `docker compose up` / `build` starts nothing. The file remains for
reference and for CI's opt-in browser job, which builds a disposable copy on the
runner with `COMPOSE_PROFILES=dev`. The `docker/node/` image (Alpine Chromium for
Playwright) and `docker/nginx/default.conf` belong to that stack.

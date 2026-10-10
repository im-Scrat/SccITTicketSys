# Installation Guide

First-time setup of the **production stack** on `http://localhost:8081` — the
only stack since the development stack (`:8080`) was retired on 2026-10-10.

## Prerequisites

- **Docker Desktop** (running, Linux containers)
- **Git**, and on Windows **Git Bash** (the `scripts/` are POSIX `sh`)

> Host PHP / Composer / Node are **not required** — everything runs in
> containers. They're only handy for editor tooling.

## 1. Clone & configure

```bash
git clone <repo-url> SccITTicketSys
cd SccITTicketSys

# Compose-level variables (POSTGRES_*, project name, ports)
cp .env.example .env

# Laravel, production
cp backend/.env.production.example backend/.env.production
```

In `backend/.env.production`, replace `APP_KEY=base64:GENERATE_A_REAL_KEY` with
a real key — for example the output of
`echo "base64:$(head -c 32 /dev/urandom | base64)"` in Git Bash — and review
the rest of the template ([ENVIRONMENT.md](ENVIRONMENT.md)).

Put the Gemini API key in `secrets/gemini_api_key.txt` (gitignored; see
`secrets/README.md`). It is mounted as a Compose secret, never baked into an
image or a config cache.

## 2. Build and start

```bash
docker compose -p sccit_prod -f compose.prod.yaml up -d --build
docker compose -p sccit_prod -f compose.prod.yaml exec app_prod php artisan migrate --force
docker compose -p sccit_prod -f compose.prod.yaml exec app_prod php artisan db:seed --force   # first install only
```

`-p sccit_prod` is required on every command (the root `.env` sets
`COMPOSE_PROJECT_NAME=sccit`). After the first install, ship changes with
`sh scripts/deploy-prod.sh` instead — see [DEVELOPMENT.md](DEVELOPMENT.md).

## 3. Verify

| What | URL / command |
| ---- | ------------- |
| App | http://localhost:8081 |
| Backend health | http://localhost:8081/up → `200` |
| API health | http://localhost:8081/api/health → `{"status":"healthy",...}` (app, database, redis) |
| Mailpit UI | http://localhost:8026 |

```bash
docker compose -p sccit_prod -f compose.prod.yaml ps   # all services Up / healthy
sh scripts/smoke.sh --stack prod                        # HTTP smoke test
```

PostgreSQL is not published to the host; use
`docker compose -p sccit_prod -f compose.prod.yaml exec postgres_prod psql -U postgres -d school_it_service_management`.

See [PRODUCTION_DEVELOPER_FILE_MAP.md](PRODUCTION_DEVELOPER_FILE_MAP.md) for
where everything lives, and [DEVELOPMENT.md](DEVELOPMENT.md) for the
day-to-day workflow.

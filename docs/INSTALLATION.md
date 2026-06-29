# Installation Guide

Get the entire stack running locally with Docker.

## Prerequisites

- **Docker Desktop** (running, Linux containers)
- **Git**
- Windows host: see [ENVIRONMENT.md](ENVIRONMENT.md) for the one-time
  PostgreSQL port note.

> Host PHP / Composer / Node are **not required** — everything runs in
> containers. They're only handy for editor tooling.

## 1. Clone & configure

```bash
git clone <repo-url> SccITTicketSys
cd SccITTicketSys

# Root (Docker) environment
cp .env.example .env

# Backend (Laravel) environment
cp backend/.env.example backend/.env
```

## 2. Free host port 5432 (Windows only, one-time)

This project's PostgreSQL container binds host port **5432**. If you have a
local Windows PostgreSQL service, stop it so the port is free — see
[ENVIRONMENT.md → Windows PostgreSQL](ENVIRONMENT.md#windows-postgresql-service).

## 3. Start the stack

```bash
docker compose up -d --build
```

First run builds the PHP and Node images and pulls Postgres/Redis/Mailpit.

## 4. Generate the app key & migrate

```bash
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose exec app php artisan storage:link
```

## 5. Verify

| What | URL / command |
| ---- | ------------- |
| App (frontend) | http://localhost:8080 |
| Backend health | http://localhost:8080/up → `200` |
| API smoke test | http://localhost:8080/api/health → `{"status":"healthy",...}` |
| Mailpit UI | http://localhost:8025 |
| PostgreSQL (pgAdmin) | `localhost:5432`, db `school_it_service_management`, user `postgres` |

```bash
docker compose ps        # all services Up / healthy
```

## Common follow-ups

```bash
make help                # list developer shortcuts (or see scripts/)
docker compose logs -f   # tail logs
```

See [DEVELOPMENT.md](DEVELOPMENT.md) for the day-to-day workflow.

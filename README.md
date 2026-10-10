# AI-Powered School IT Asset & Service Management System

A production-grade, fully Dockerized platform for managing a school's IT service
tickets, computer inventory, assets, preventive maintenance, repair history, and
(later) AI troubleshooting + an interactive floor-plan module.

> **Status:** Environment bootstrap. No business features are implemented yet —
> this repository currently provides the development environment, project
> architecture, tooling, and documentation only.

## Tech stack

| Layer            | Technology                                              |
| ---------------- | ------------------------------------------------------- |
| Backend          | Laravel 13 · PHP 8.4                                    |
| Frontend         | React 19 · Vite · TypeScript · Tailwind CSS v4         |
| Database         | PostgreSQL 17 (with `pgvector` for future RAG)          |
| Cache / Queue    | Redis (cache, sessions, queues)                         |
| Web server       | Nginx (single origin: serves frontend + proxies `/api`) |
| Auth             | Laravel Sanctum (cookie-based SPA auth)                 |
| Containerization | Docker · Docker Compose                                 |
| Mail (dev)       | Mailpit                                                 |

## Architecture at a glance

- **Single origin** — the browser talks only to Nginx on `http://localhost:8081`
  (the production stack — the only stack since the development stack on `:8080`
  was retired on 2026-10-10). Nginx serves the built React app and proxies
  `/api`, `/sanctum`, `/up` to Laravel. No CORS; Sanctum cookies work out of the box.
- **Domain-oriented backend** — business logic lives under `backend/app/Domains/*`
  (Tickets, Assets, Maintenance, …) so new modules (e.g. the future
  **Interactive Floor Plan**) can be added without restructuring.
- **One PHP-FPM image, several roles** — `app_prod` (FPM), `queue_prod`,
  `queue_ai_prod` (AI worker pool), `scheduler_prod`, `reverb_prod` (WebSockets) —
  plus `nginx_prod` (static SPA + reverse proxy), `postgres_prod`, `redis_prod`,
  `mailpit_prod`. Defined in `compose.prod.yaml`.

## Repository layout

```
.
├── backend/          # Laravel 13 application
├── frontend/         # React 19 + Vite + TS application
├── docker/           # Dockerfiles & service configs
│   ├── php/          #   PHP-FPM image (app/queue/scheduler)
│   ├── nginx/        #   Nginx site config
│   ├── node/         #   Node image (Vite dev / prod build)
│   └── postgres/     #   init scripts (enable pgvector, etc.)
├── docs/             # Installation, Docker, Development guides
├── scripts/          # gates, deploy/rollback, backup/restore/verify
├── .github/          # CI workflows
├── compose.prod.yaml # Production stack (:8081) — the only stack
├── compose.yaml      # Retired development stack (2026-10-10), `dev` profile only
└── .env.example      # Compose-level environment template
```

## Quick start

> Where to edit what, and how a change reaches `:8081`:
> [docs/PRODUCTION_DEVELOPER_FILE_MAP.md](docs/PRODUCTION_DEVELOPER_FILE_MAP.md).
> First-time setup: [docs/INSTALLATION.md](docs/INSTALLATION.md).

```bash
docker compose -p sccit_prod -f compose.prod.yaml ps   # status (-p sccit_prod is required)
sh scripts/gates.sh                                     # quality gate, isolated
sh scripts/deploy-prod.sh                               # ship a change to :8081
```

| Service          | URL                                |
| ---------------- | ---------------------------------- |
| App (Nginx)      | http://localhost:8081              |
| Laravel health   | http://localhost:8081/up           |
| Mailpit UI       | http://localhost:8026              |
| PostgreSQL       | not published to the host — `docker compose -p sccit_prod -f compose.prod.yaml exec postgres_prod psql -U postgres -d school_it_service_management` |

## Documentation

- [Production Developer File Map](docs/PRODUCTION_DEVELOPER_FILE_MAP.md)
- [Installation Guide](docs/INSTALLATION.md)
- [Environment Setup](docs/ENVIRONMENT.md)
- [Project Structure](docs/PROJECT_STRUCTURE.md)
- [Docker Guide](docs/DOCKER.md)
- [Development Guide](docs/DEVELOPMENT.md)
- [Testing & Quality Gates](docs/TESTING.md)
- [Operations Runbook](docs/OPERATIONS.md)

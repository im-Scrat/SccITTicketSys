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
| Backend          | Laravel 12 · PHP 8.4                                    |
| Frontend         | React 19 · Vite · TypeScript · Tailwind CSS v4         |
| Database         | PostgreSQL 17 (with `pgvector` for future RAG)          |
| Cache / Queue    | Redis (cache, sessions, queues)                         |
| Web server       | Nginx (single origin: serves frontend + proxies `/api`) |
| Auth             | Laravel Sanctum (cookie-based SPA auth)                 |
| Containerization | Docker · Docker Compose                                 |
| Mail (dev)       | Mailpit                                                 |

## Architecture at a glance

- **Single origin** — the browser talks only to Nginx on `http://localhost:8080`.
  Nginx serves the React app and proxies `/api`, `/sanctum`, `/up` to Laravel.
  No CORS; Sanctum cookies work out of the box.
- **Domain-oriented backend** — business logic lives under `backend/app/Domains/*`
  (Tickets, Assets, Maintenance, …) so new modules (e.g. the future
  **Interactive Floor Plan**) can be added without restructuring.
- **One PHP-FPM image, three roles** — `app` (FPM), `queue` (worker),
  `scheduler` — plus `nginx`, `postgres`, `redis`, `node`, `mailpit`.

## Repository layout

```
.
├── backend/          # Laravel 12 application
├── frontend/         # React 19 + Vite + TS application
├── docker/           # Dockerfiles & service configs
│   ├── php/          #   PHP-FPM image (app/queue/scheduler)
│   ├── nginx/        #   Nginx site config
│   ├── node/         #   Node image (Vite dev / prod build)
│   └── postgres/     #   init scripts (enable pgvector, etc.)
├── docs/             # Installation, Docker, Development guides
├── scripts/          # DX helpers (up/down/migrate/test/backup…)
├── .github/          # CI workflows
├── compose.yaml      # Development stack
└── .env.example      # Compose-level environment template
```

## Quick start

> Full instructions: [docs/INSTALLATION.md](docs/INSTALLATION.md)

```bash
cp .env.example .env
docker compose up -d
```

Then:

| Service          | URL                                |
| ---------------- | ---------------------------------- |
| App (Nginx)      | http://localhost:8080              |
| Laravel health   | http://localhost:8080/up           |
| Mailpit UI       | http://localhost:8025              |
| PostgreSQL       | localhost:5432 (pgAdmin)           |

## Documentation

- [Installation Guide](docs/INSTALLATION.md)
- [Environment Setup](docs/ENVIRONMENT.md)
- [Project Structure](docs/PROJECT_STRUCTURE.md)
- [Docker Guide](docs/DOCKER.md)
- [Development Guide](docs/DEVELOPMENT.md)

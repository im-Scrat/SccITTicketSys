# Environment Setup Guide

## Environment files

| File | Purpose | Tracked? |
| ---- | ------- | -------- |
| `.env` | Docker Compose variables (DB creds, ports, project name) | No (gitignored) |
| `.env.example` | Template for the above | Yes |
| `backend/.env` | Laravel app config | No (gitignored) |
| `backend/.env.example` | Template for the above | Yes |

Copy each `*.example` to its real file before first run.

## Key variables (Docker — root `.env`)

| Variable | Default | Notes |
| -------- | ------- | ----- |
| `COMPOSE_PROJECT_NAME` | `sccit` | Prefixes all containers/volumes/networks |
| `POSTGRES_DB` | `school_it_service_management` | |
| `POSTGRES_USER` / `POSTGRES_PASSWORD` | `postgres` / `scc26` | Dev only — see Security |
| `POSTGRES_HOST_PORT` | `5432` | Bound to `127.0.0.1` only |
| `REDIS_PASSWORD` | `scc26redis` | Redis requires auth |
| `APP_HOST_PORT` | `8080` | Single-origin Nginx entrypoint |
| `MAILPIT_SMTP_PORT` / `MAILPIT_UI_PORT` | `1025` / `8025` | |

## Key variables (Laravel — `backend/.env`)

- `DB_HOST=postgres`, `REDIS_HOST=redis`, `MAIL_HOST=mailpit` — these are
  **compose service names**, resolvable only inside the Docker network.
- `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis` —
  scale-ready (all shared state in Redis).
- `SANCTUM_STATEFUL_DOMAINS=localhost:8080,...` + `SESSION_DOMAIN=localhost`
  — required for single-origin Sanctum cookie auth.
- Reverb placeholders are present (commented) for future real-time.

## <a name="windows-postgresql-service"></a>Windows PostgreSQL service

This project runs PostgreSQL **in Docker** on host port `5432`. A locally
installed Windows PostgreSQL also uses `5432`, so it must be stopped to avoid
a conflict. **Your Windows PostgreSQL is not removed — only stopped**, and was
set to **Manual** start so it won't reclaim the port on reboot.

Replace `postgresql-x64-18` with your service name if different
(`Get-Service *postgres*` to find it).

**Stop it (run PowerShell as Administrator):**

```powershell
Stop-Service -Name 'postgresql-x64-18' -Force
Set-Service  -Name 'postgresql-x64-18' -StartupType Manual
```

**Re-enable it later (Administrator):**

```powershell
Set-Service  -Name 'postgresql-x64-18' -StartupType Automatic
Start-Service -Name 'postgresql-x64-18'
```

> If you'd rather keep Windows PostgreSQL running, change `POSTGRES_HOST_PORT`
> in `.env` to e.g. `5433` and connect pgAdmin to `localhost:5433`. The
> container's internal port stays `5432`, so nothing else changes.

## Security notes (dev vs production)

- The dev DB uses the `postgres` superuser with a weak password — acceptable
  locally, **never in production**. Production must use a least-privilege role
  and a secrets manager.
- Published DB/Redis ports bind to `127.0.0.1` only (not exposed on the LAN).
- `.env` files are gitignored; never commit real secrets. `APP_KEY` is
  generated per environment.

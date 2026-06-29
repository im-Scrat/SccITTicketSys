# Development Guide

Day-to-day workflow. Everything runs in Docker; use `make <target>`
(GNU make), the `scripts/*.sh` files (Git Bash), or raw `docker compose`.

## Start / stop

```bash
make up         # or: docker compose up -d
make down       # or: docker compose down
make logs       # tail logs
make ps         # service status
```

App → http://localhost:8080 · Mailpit → http://localhost:8025

## Hot reload (HMR)

The Vite dev server runs in the `node` container and is proxied through Nginx.
Editing files under `frontend/src` reloads the browser automatically (the HMR
WebSocket is proxied on `:8080`). Edits to `backend/app` are picked up
immediately (OPcache revalidates in dev).

## Backend (Laravel)

```bash
make shell                                   # bash in the app container
docker compose exec app php artisan migrate
docker compose exec app php artisan make:... # generators
make fresh                                   # migrate:fresh
make psql                                    # psql shell
```

Artisan/Composer run **inside** the `app` container (service names like
`postgres` resolve only on the Docker network).

## Frontend (React)

```bash
docker compose exec node npm install <pkg>   # add a dependency
```

After changing `frontend/package.json`, rebuild so the `node_modules` volume
reseeds: `docker compose up -d --build node`.

## Quality gates

```bash
make lint     # Pint --test + Larastan + ESLint
make format   # Pint + Prettier (auto-fix)
make test     # Pest + Vitest
```

Backend tests run against the dedicated `school_it_service_management_test`
database (configured in `backend/phpunit.xml`). CI runs the same gates —
see [`.github/workflows/ci.yml`](../.github/workflows/ci.yml).

## Database backup / restore

```bash
make backup                                   # -> backups/<db>-<ts>.sql.gz
make restore FILE=backups/<db>-<ts>.sql.gz
```

## Adding a feature later (the intended flow)

1. Backend: create classes under `app/Domains/<Domain>/...`, routes in
   `routes/api.php`, migrations in `database/migrations/`.
2. Frontend: create a slice under `src/features/<feature>/` and call the API
   via `src/services/api.ts` (Sanctum cookies handled automatically).
3. Add tests (Pest / Vitest). Run `make lint && make test`.

## Enabling real-time later (Reverb)

```bash
docker compose exec app composer require laravel/reverb
docker compose exec app php artisan install:broadcasting
# set BROADCAST_CONNECTION=reverb + REVERB_* in backend/.env, add a reverb container
```

## Troubleshooting

- **Port 5432 in use** → stop Windows PostgreSQL (see [ENVIRONMENT.md](ENVIRONMENT.md)).
- **Port 8080 in use** → change `APP_HOST_PORT` in `.env`.
- **`/` returns 502** → the `node` container isn't up yet; `docker compose up -d node`.
- **DB/Redis errors from Laravel** → check `docker compose ps` health and
  that `backend/.env` host values are `postgres` / `redis`.
- **Reset everything** → `docker compose down -v` then `up -d --build`
  (this **deletes** the database volume).

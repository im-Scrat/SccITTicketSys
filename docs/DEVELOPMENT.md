# Development Guide

Day-to-day workflow. **Since 2026-10-10 there is one stack: production, on
`http://localhost:8081`.** The bind-mounted development stack (`:8080`, Vite
HMR) was retired, so there is no hot reload on `:8081` — a change reaches the
site through the quality gate and a rebuild of the production images. To see
frontend edits live before that, run `npm run dev:live` in `frontend/` and open
`http://localhost:5173`: Vite on this PC, forwarding to production's API and
data ([PRODUCTION_DEVELOPER_FILE_MAP.md](PRODUCTION_DEVELOPER_FILE_MAP.md) §1).
Teammates can use the same preview over Tailscale Serve (tailnet-only), as
described in that section under "Sharing the preview with the team".

Where each feature lives, how the layers connect and which files to open:
[PRODUCTION_DEVELOPER_FILE_MAP.md](PRODUCTION_DEVELOPER_FILE_MAP.md).

## The loop

```bash
# 1. edit source under backend/ and frontend/src/
sh scripts/gates.sh                                  # 2. full gate, in a disposable environment
sh scripts/deploy-prod.sh                            # 3. gate -> verified snapshot -> build/tag -> deploy -> migrate -> smoke
docker compose -p sccit_prod -f compose.prod.yaml ps # 4. check
```

`deploy-prod.sh` asks before it changes anything. It refuses a dirty tracked
tree unless you pass `--allow-dirty`, because the image is built from the
working tree — uncommitted changes included. Rolling back:
[OPERATIONS.md](OPERATIONS.md) §3.

## Inspecting production (read-only)

```bash
docker compose -p sccit_prod -f compose.prod.yaml logs -f app_prod
docker compose -p sccit_prod -f compose.prod.yaml exec -T app_prod php artisan migrate:status
docker compose -p sccit_prod -f compose.prod.yaml exec -T app_prod php artisan route:list --path=api/tickets
docker compose -p sccit_prod -f compose.prod.yaml exec postgres_prod psql -U postgres -d school_it_service_management
```

Artisan runs **inside** `app_prod`, whose image has no dev packages (no Pest,
Pint or PHPStan — those run in the gate). Generators (`php artisan make:*`)
would write inside the container, not your source tree: create files in the
editor, following a neighbouring file.

## Quality gates

```bash
sh scripts/gates.sh                  # Pint, PHPStan, Pest, tsc, ESLint, Prettier, Vitest, build
sh scripts/gates.sh --only backend   # or --only frontend
```

`scripts/gates.sh` exports the working tree into a disposable environment with
its own PostgreSQL and Redis and runs every gate in order, always attempting each
one. Known flaky tests and the browser suites (now CI-only) are covered in
[TESTING.md](TESTING.md). CI runs the same gates —
see [`.github/workflows/ci.yml`](../.github/workflows/ci.yml).

## Database backup / restore

```bash
sh scripts/backup.sh --stack prod --verify                       # -> backups/prod/<ts>/, proven restorable
sh scripts/restore.sh --stack prod --dry-run backups/prod/<ts>   # every check, no change
```

Snapshots are directories with a manifest, proven in a disposable database —
see [OPERATIONS.md](OPERATIONS.md) §2. `restore.sh` without `--dry-run`
overwrites production.

## Adding a feature (the intended flow)

1. Backend: classes under `backend/app/Domains/<Domain>/` (Actions,
   Http/{Controllers,Requests,Resources}, Policies, Services), routes in
   `backend/routes/api.php`, models in `backend/app/Models/`, migrations in
   `backend/database/migrations/`.
2. Frontend: a slice under `frontend/src/features/<feature>/` (api, hooks,
   components, pages, types), routes in `frontend/src/App.tsx`, navigation in
   `frontend/src/layouts/navConfig.ts`, HTTP through `frontend/src/services/api.ts`.
3. Tests: `backend/tests/Feature/<Domain>/`, and `*.test.tsx` beside the
   frontend code. Run `sh scripts/gates.sh`.
4. Ship: `sh scripts/deploy-prod.sh`.

## Dependencies

Production installs from `backend/composer.lock` and `frontend/package-lock.json`
when the images are built. The old procedure (`scripts/dep-update.sh`) needed
the retired dev stack; how to regenerate lock files now is an open owner
decision.

## Troubleshooting

- **A change doesn't show up** → the image was not rebuilt: `sh scripts/deploy-prod.sh`.
- **`/up` is not 200** → `docker compose -p sccit_prod -f compose.prod.yaml ps`, then `logs app_prod`.
- **Port 8081 in use** → set `PROD_HTTP_PORT` in the root `.env`.
- **Never** `docker compose ... down -v` (deletes the production database), and
  never a bare `docker compose up` — the retired dev stack is profile-gated and
  starts nothing.

# Project Structure

```
SccITTicketSys/
├── backend/                 # Laravel 13 API
│   ├── app/
│   │   ├── Domains/         # modular monolith — business logic by domain
│   │   │   ├── Tickets/  Assets/  Maintenance/
│   │   │   ├── KnowledgeBase/  Analytics/
│   │   │   └── FloorPlan/   # FUTURE module (seam only)
│   │   ├── Support/         # shared Concerns/DTOs/Enums/Traits
│   │   ├── Http/  Models/  Providers/   # framework defaults
│   ├── routes/  config/  database/  tests/
│   ├── phpunit.xml  pint.json  phpstan.neon
│   └── .env.example
├── frontend/                # React 19 + Vite + TS
│   ├── src/
│   │   ├── components/ pages/ layouts/ hooks/ contexts/
│   │   ├── stores/ services/ types/ utils/ assets/
│   │   └── features/        # feature slices (tickets, assets, …, floor-plan)
│   ├── eslint.config.js  .prettierrc.json  vite.config.ts
│   └── .env (via Vite)
├── docker/
│   ├── php/      # PHP-FPM image + php.ini + www.conf + healthcheck
│   ├── nginx/    # single-origin site config
│   ├── node/     # Node/Vite image
│   └── postgres/ # init scripts (pgvector)
├── docs/         # this documentation
├── scripts/      # up/down/migrate/fresh/test/backup/restore
├── .github/workflows/ci.yml
├── compose.yaml
├── Makefile
└── .env.example
```

## Backend: domain-oriented layout

Business logic is grouped **by domain** (`App\Domains\<Domain>`), each owning
its `Actions/ DTOs/ Enums/ Events/ Jobs/ Listeners/ Models/ Notifications/
Policies/ Services/` and `Http/{Controllers,Requests,Resources}`. Shared
non-domain code lives in `App\Support`. No repositories — Eloquent +
Services + Actions. See [`backend/app/Domains/README.md`](../backend/app/Domains/README.md).

## Frontend: feature-slice layout

Cross-cutting building blocks live in the top-level `src/` folders; each
feature under `src/features/<feature>/` is a self-contained slice mirroring a
backend domain. See [`frontend/src/features/README.md`](../frontend/src/features/README.md).

## Future module readiness

`FloorPlan` (backend domain) and `floor-plan` (frontend feature) are reserved
seams for the Interactive Floor Plan module — documented, not implemented. The
DB has `pgvector` enabled for future RAG, and Reverb (real-time) env
placeholders are in place.

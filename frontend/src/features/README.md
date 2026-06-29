# Features (feature-slice architecture)

Each feature is a **self-contained vertical slice** mirroring a backend domain.
A typical feature looks like:

```
features/<feature>/
├── components/   # feature-specific UI
├── hooks/        # feature hooks (e.g. useTickets)
├── api/          # API calls (built on src/services/api.ts)
├── stores/       # feature-local Zustand stores
├── types/        # feature types
└── index.ts      # public surface of the feature
```

Shared/cross-feature building blocks stay in the top-level `src/` folders
(`components`, `hooks`, `services`, `stores`, `types`, `utils`, `layouts`,
`contexts`).

## Current features

`tickets`, `assets`, `maintenance`, `knowledge-base`, `analytics`, and
`floor-plan` (**future** — see its README).

> Seams only — no feature logic is implemented during the bootstrap.

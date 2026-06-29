# Domains (modular monolith)

Business logic is organized **by domain**, not by technical type, so the system
stays modular and new modules (e.g. the future Interactive Floor Plan) can be
added without restructuring.

Each domain owns its full vertical slice and is namespaced `App\Domains\<Domain>`
(autoloaded by the default `App\` → `app/` PSR-4 mapping — no Composer changes
needed):

```
app/Domains/<Domain>/
├── Actions/            # single-purpose use-case actions
├── DTOs/               # data transfer objects
├── Enums/              # domain enums
├── Events/             # domain events
├── Jobs/               # queued jobs
├── Listeners/          # event listeners
├── Models/             # Eloquent models
├── Notifications/      # mail / database notifications
├── Policies/           # authorization policies
├── Services/           # orchestration / domain services
└── Http/
    ├── Controllers/
    ├── Requests/       # form requests (validation)
    └── Resources/      # API resources (serialization)
```

## Conventions

- **No Repositories.** Eloquent already is the data layer; use
  `Services` + `Actions` + Eloquent. Introduce a repository only if a genuine
  need appears (the spec said "only if justified").
- **Shared code** that isn't domain-specific lives in [`App\Support`](../Support).
- **Cross-domain communication** should prefer events/listeners over direct
  calls between domains, to keep boundaries clean.

## Current domains

| Domain          | Responsibility |
| --------------- | -------------- |
| `Tickets`       | IT service tickets, technician assignment, repair history |
| `Assets`        | Computer inventory, asset management, QR verification |
| `Maintenance`   | Preventive maintenance scheduling & timelines |
| `KnowledgeBase` | AI knowledge base & troubleshooting (future: Gemini + RAG) |
| `Analytics`     | Analytics, reports, dashboards |
| `FloorPlan`     | **Future** interactive floor-plan module — see its README |

> These folders are **seams only**. No business logic exists yet, per the
> bootstrap scope.

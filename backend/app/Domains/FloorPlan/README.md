# FloorPlan domain — FUTURE MODULE (do NOT implement yet)

This domain is an **architecture seam** for the Interactive Floor Plan module.
Per the project brief it must NOT be built now, but the project must support it
later **without major refactoring**. This folder reserves its place in the
domain structure so the module drops in cleanly.

## Planned services (to be implemented later, not now)

These map to `App\Domains\FloorPlan\Services`:

- `BuildingService` — buildings
- `FloorService` — floors per building
- `LaboratoryLayoutService` — lab/office room layouts
- `AssetPositionService` — PC unit positions (x/y, snap-to-grid)
- `FloorPlanService` — overall plan orchestration
- `CoordinateService` — coordinate math / transforms

## Planned capabilities

Interactive lab/building/floor layouts; drag-and-drop + snap-to-grid PC
positioning; zoom/pan; save layout positions; real-time position updates;
status-colored PC icons (Online/Offline/Under Maintenance/Assigned/Available);
click-to-open info panel (specs, hardware, QR, repair/upgrade history, AI
prediction, ticket history, maintenance timeline). Admin-only editing.

Future extensions: network topology visualization, heat maps, asset density,
AI-predicted failures on the map, indoor navigation.

## Data & real-time readiness (already prepared by the bootstrap)

- **Coordinates** — plain numeric columns suffice for x/y; PostgreSQL is ready.
  For advanced spatial features (indoor navigation, heat maps) the Postgres
  image can later enable PostGIS.
- **Vectors / AI** — `pgvector` is already enabled in the database for the
  KnowledgeBase RAG features.
- **Real-time** — "real-time position updates" will use **Laravel Reverb**
  (first-party WebSockets). Broadcasting config + env placeholders are already
  in place; enabling it later is `composer require laravel/reverb` +
  `php artisan install:broadcasting` (see project docs). No real-time code now.
- **Frontend** — see `frontend/src/features/floor-plan/README.md` for the
  canvas/DnD/zoom/pan/minimap rendering seam.

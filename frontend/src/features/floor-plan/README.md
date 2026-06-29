# floor-plan feature — FUTURE (do NOT implement yet)

Frontend seam for the Interactive Floor Plan module. Reserved so the rendering
layer can be added later without restructuring. **Nothing is implemented now.**

## Planned rendering capabilities

- Interactive canvas (lab/building/floor layouts)
- Drag-and-drop + snap-to-grid PC positioning
- Zoom & pan
- Minimap
- Right-click context menus
- Information side panels
- Asset-layer rendering
- Grid system
- Status-colored PC icons (Online / Offline / Under Maintenance / Assigned / Available)

## Notes for the future implementation

- Consume the backend `App\Domains\FloorPlan` API via `src/services/api.ts`.
- Real-time position updates will arrive over **Laravel Reverb** (WebSockets);
  wire a client (e.g. Laravel Echo) when the module is built.
- Heavy canvas state should live in a feature-local Zustand store under
  `features/floor-plan/stores`.

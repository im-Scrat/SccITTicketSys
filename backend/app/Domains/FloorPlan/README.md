# FloorPlan domain — Interactive Floor Plan (implemented, Phase 2.8 WP-B..G)

Administrator-only room maps: PC units placed on a per-room, versioned
layout, coloured and shaped by status, with live updates over Reverb.

> This file once said "FUTURE MODULE (do NOT implement yet)". That was the
> Phase 2 bootstrap seam. The module has been built since; the SDD (DD-* for
> WP-B..G) and `routes/api.php` ("Floor plan — ADMINISTRATOR ONLY") are the
> authority. Updated during the Phase 2.9 pre-commit fix pass.

## What exists

| Piece | Where |
|---|---|
| Read a room's plan (active layout + placed/unplaced units) | `GET /api/admin/floor-plan/rooms/{room}` → `FloorPlanService::roomPlan` → `RoomPlanResource` |
| Create a layout version (never active on creation) | `POST …/rooms/{room}/layouts` → `CreateRoomLayout` |
| Activate a version (positions do **not** carry over) | `POST …/rooms/{room}/layouts/{version}/activate` → `ActivateRoomLayout` |
| Place / move a unit (snap, clamp, 409 on stale `expected_updated_at`) | `PATCH …/layouts/{version}/positions/{pcUnit}` → `PlacePcUnit` |
| Authorization | `RoomLayoutPolicy` / `FloorPlanPositionPolicy`: Administrator **role + permission**; routes gate on policy abilities, never on `can:floorplan.*` |
| Realtime | `PositionUpdated`, `PcStatusChanged` on the room's private channel |
| Defaults | `FloorPlanDefaults` (`floor_plan.snap_to_grid`, `floor_plan.default_grid_size`) |

`FloorPlanPcResource` carries, per placed unit, the status (value/label/tone
from `PcStatus`), the position, the concurrency token, and `open_ticket`
(reference of the newest open ticket on that machine, for the map's alert
state and tooltip).

## Frontend

`frontend/src/features/floor-plan/` — SVG canvas (drag, snap, keyboard
placement), create-layout form, legend, inspector panel, Zustand viewport
store, Echo subscription. See its README.

## Still future

Walls/furniture shapes, network topology, heat maps, indoor navigation.

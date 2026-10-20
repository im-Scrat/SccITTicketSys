# FloorPlan domain — the Floorplan (Phase 2.8 WP-B..G, completed Phase 2.11)

Administrator-only room layouts: real PC units and layout-only fixtures on a
per-room, versioned layout, coloured by repair status, edited as a draft and
saved whole, with live notices and presence over Reverb. "Floorplan" is the
user-facing name; code and routes keep `floor-plan` / `FloorPlan`.

The SDD is the authority for the decisions (DD-100 status model, DD-101 save
and merge, DD-102 fixtures and geometry, DD-103 presence, DD-104 position
release), and `routes/api.php` ("Floorplan — ADMINISTRATOR ONLY") for the
gates.

## What exists

| Piece | Where |
|---|---|
| Room overview (cards: status counts, placed/total, layout state, preview) | `GET /api/admin/floor-plan/rooms` → `FloorPlanOverviewQuery` → `FloorPlanRoomSummaryResource` |
| Read a room's plan (layout + revision, fixtures, placed/unplaced units) | `GET /api/admin/floor-plan/rooms/{room}` → `FloorPlanService::roomPlan` → `RoomPlanResource` |
| **Save the whole layout** (workstations, fixtures, size/grid; 409 `layout_stale` on an old revision) | `PUT …/rooms/{room}/layouts/{version}` → `SaveRoomLayout` |
| Create a layout version (`activate: true` for first-time setup) | `POST …/rooms/{room}/layouts` → `CreateRoomLayout` |
| Activate a version (positions do **not** carry over) | `POST …/rooms/{room}/layouts/{version}/activate` → `ActivateRoomLayout` |
| Geometry rules (inside the room; workstations never overlap) | `LayoutGeometry`, mirrored by the frontend's `lib/geometry.ts` |
| A machine leaving a room releases its place | `ReleasePcUnitPositions` (called by `UpdatePcUnit`, `ReassignRoomOccupants`, `ArchivePcUnit`) |
| Authorization | `RoomLayoutPolicy` over `FloorPlanAccess`: Administrator **role + permission**; routes gate on policy abilities, never on `can:floorplan.*` |
| Realtime | `RoomPlanChanged`, `PcStatusChanged` on `floor-plan.room.{uuid}`; presence on `floor-plan.editors.{uuid}` |
| Defaults | `FloorPlanDefaults` (`WORKSTATION_SIZE` 40, `MAX_FIXTURES` 500, `floor_plan.snap_to_grid`, `floor_plan.default_grid_size`) |

Retired in Phase 2.11: the per-machine `PATCH …/positions/{pcUnit}`
(`PlacePcUnit`, `FloorPlanPositionPolicy`, `PositionUpdated`) and
`CoordinateService` — one write path, one rule set.

## Frontend

`frontend/src/features/floor-plan/` — overview, editor workspace, canvas,
panels, per-room editor store. See its README.

## Still future

A layout version-history browser, background
images (column exists, no upload), network topology, heat maps, indoor
navigation.

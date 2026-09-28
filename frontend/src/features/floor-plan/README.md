# floor-plan feature

Frontend for the Interactive Floor Plan (Phase 2.8). **Administrator-only.**

## Delivered (WP-C — read-only visualization)

- `pages/FloorPlanPage` — room picker (rooms come from the Locations directory).
- `pages/FloorPlanRoomPage` — one room's map, legend, controls and text alternative.
- `components/FloorPlanCanvas` — SVG, `viewBox` = a window onto the layout in its own
  pixels; grid as an SVG `<pattern>`; pan (drag, arrow keys, buttons), zoom
  (buttons, `+`/`-`, Ctrl/⌘ + wheel), reset.
- `stores/useViewportStore` — Zustand, **view state only** (zoom, pan, layout size).
  Server data stays in TanStack Query; authorization stays with the backend and the
  route guard.
- `guards/RequireFloorPlan` — role **and** permission (mirrors the backend
  `FloorPlanAccess`); fails closed.

Status is drawn with three channels — a per-status **shape**, the server's **tone**
(colour) and the server's written **label**. The label/tone come from
`PcStatus::label()/tone()` in the API payload; nothing here re-derives them.

## Not built yet

Dragging or snapping machines, saving positions, layout create/activate/versioning,
the PC inspector, live updates over Reverb, minimap, context menus. Later packages.

## Authorization

The backend is authoritative. Every floor-plan endpoint is gated by the **policy**
ability on `RoomLayout` and by `RoomLayoutService` — never by a `floorplan.*`
permission string, which a per-user grant could satisfy. The guard, the nav entry and
the room page (which shows the Forbidden screen on any 401/403) are UX reflections of
that, not the control.

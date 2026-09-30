---
title: "Session 08 — WP-C: Admin-only Floor Plan Visualization"
date: 2026-09-28
---

# Session 08 — WP-C: Admin-only Floor Plan Visualization

**Written for:** the project owner, as the WP-C checkpoint report.

## 1. Status: WP-C PASS

| Item | Value |
|---|---|
| WP-C commit | `b845ea3ffd03f2f7baf1add22987b862fb0d6200` (37 files, +2839 / −26) |
| WP-B commit (unchanged, ancestor) | `22607c1d39bf455b5890cfb0eb1572af3422798a` |
| Live remote HEAD | `742ded58…` — **not pushed** |
| `c9ab8a4` | still an ancestor |
| Migrations | **0** |

## 2. WP-B pre-flight (read-only) — accepted

WP-B commit `22607c1` exists, is local-only, and touches 14 files, all backend: the six domain classes, `PcStatus`, `ActivityAction`, `AppServiceProvider`, `PermissionSeeder`, and four test files. It contains no frontend, routes, controllers, resources, migrations, Reverb or AI. `FloorPlanAccess` (role AND permission), both policies and their registration were re-read at HEAD and match the WP-B report. The 21 pre-existing modifications matched their session-start fingerprint.

## 3. Architecture

- **One read-only endpoint:** `GET /api/admin/floor-plan/rooms/{room}`. No mutation route exists; POST/PUT/PATCH/DELETE return 405.
- **Gate:** `can:viewAny,App\Models\RoomLayout` — a *policy ability*, never `can:floorplan.*`. Behind it, `FloorPlanController` → `FloorPlanService` → WP-B `RoomLayoutService` (which authorizes again through `RoomLayoutPolicy` → `FloorPlanAccess`). Two independent layers.
- **`{room}` is a plain string**, resolved inside the service after authorization. A route-bound model would resolve before the `can` middleware and turn an unknown uuid into a 404 for callers about to get a 403 — an existence oracle.
- **Read model:** `RoomPlan` DTO → `RoomPlanResource`. Payload is narrow: room (uuid, name, code, floor, building), active layout (version, width, height, grid_size), placed PCs (uuid, name, unit_code, status value + label + tone from `PcStatus`, x, y, rotation, z_index), `unplaced_count`. No serial, IP, MAC, asset tag, hostname, QR or numeric id.
- **Frontend:** React Router SPA (the project does not use Inertia). `features/floor-plan/` with api, hooks, lib, stores, components, pages, guards.

## 4. SVG implementation

- `viewBox` is a window onto the layout **in the layout's own pixels**: `x y (W/zoom) (H/zoom)`. Stored coordinates are drawn as-is; no conversion to disagree with the server.
- Grid is an SVG `<pattern>` (unique id per instance, sized by `grid_size`), over a floor `rect`, inside a room boundary `rect`. Strokes use `non-scaling-stroke` so weight is constant at any zoom.
- PC nodes: shape + written name + written status label, in a group with `role="img"` and an accessible name (`PC-03, Online, position 300 by 120`).
- Pan: drag the background (pointer capture, touch-action none), arrow keys on the focused map, four toolbar buttons. Zoom: buttons, `+`/`-`, Ctrl/⌘ + wheel or pinch. Reset: button and `0`. Limits 50 %–400 %, step 1.25×; the window's centre cannot leave the layout. **Plain wheel scroll is left to the page** so the map never traps scrolling.
- Nothing writes: dragging a node pans the view, and a test asserts the node's transform is unchanged.

## 5. Zustand viewport store

`stores/useViewportStore.ts` holds **only** `zoom`, `x`, `y` and `content` (layout size), with `zoomIn/Out/To`, `panByStep`, `panByUnits`, `reset`, `setContent`. A test asserts those four data keys are the only non-function state, so no authorization, server or domain data can drift into it. A layout of a different size, or a new room, starts from the whole plan. Pure maths lives in `lib/viewport.ts`.

## 6. Reused, not recreated

`RoomLayoutService`, `RoomLayoutPolicy`, `FloorPlanAccess` (via the service and the route gate); `PcStatus::label()/tone()` (serialized into the payload, not re-derived in React); the existing `StatusPill` (picked by server tone, label overridden with the server's); `Button`, `Table`, `Alert`, `EmptyState`, `SearchInput`, `Skeleton`, `PageLoader`; the existing `RequireRole` + `RequirePermission` guards; `useRoomsList` from Locations for the room picker; `useAuth`; the `api` axios client; the theme tokens (`--grid-line`, status colours).

**Not used:** `CoordinateService` — WP-C never snaps or clamps, because nothing is written. It is the WP-D prerequisite.

## 7. Status is never colour-only

Three channels: a per-status **shape** (circle, square, triangle, diamond, hexagon, dashed octagon), the **server tone** as colour, and the **server label** as text on the node, in the legend, in the pill and in the table. A test asserts the six statuses have six distinct shape signatures and that every node carries its label; another asserts the colour class follows the *tone*, not the status name.

## 8. Authorization evidence

| Principal | Result |
|---|---|
| Administrator | 200 with payload |
| Teacher | 403 |
| Technician | 403 |
| Unauthenticated | 401 |
| Technician with per-user `floorplan.view` + `floorplan.manage` | **403** (the grants demonstrably took effect first) |
| Teacher with the same grants | **403** |
| Administrator with a per-user deny on `floorplan.view` | 403 |

Mutation check: replacing the route gate with `can:floorplan.view` made the structural guard test fail while the per-user-grant HTTP tests still passed — the controller/service/policy layer refuses on its own. Route restored.

Structural guard: a test asserts no route under `floor-plan` carries a `can:floorplan.*` middleware, so a later package cannot reintroduce the trap unnoticed.

## 9. IDOR / data-boundary evidence (Pest, encoded payload)

- Another room's layout and PCs never appear (uuid and name absent from the encoded response).
- A position whose PC has since moved to another room, or names a PC never in the room, is dropped.
- Archived PC dropped; archived room → 404 (Administrator) / 403 (others).
- Only the active layout is served; a historical version is never returned; no active layout → `layout: null`, empty plan.
- Non-administrators get **403 for a real, an unknown and an archived room** alike — no oracle.
- Malformed, SQL-shaped and numeric identifiers → 404 for everyone (router `whereUuid`).
- Inactive-layout mutation protection is WP-B's `assertEditable` (409), unchanged; WP-C has no mutation path.

## 10. Frontend authorization

- `RequireFloorPlan` = `RequireRole(administrator)` + `RequirePermission(floorplan.view)`; fails closed with no user or an unknown role.
- Nav entry carries both `permission` and `roles`; a Technician or Teacher with a `floorplan.view` grant does not see it.
- `FloorPlanRoomPage` renders the Forbidden screen on **any** 401/403 from the API and renders no plan data from a failed response; no retry on 401/403/404.
- These are UX reflections; the backend is the control.

## 11. Accessibility

- **Controls:** every toolbar control is a button with a visible text label and an accessible name that contains it; zoom level is a live `status`; buttons disable at the zoom limits; all keyboard operable.
- **Map:** focusable group with an accessible name (room, unit count) and a description of the keys; visible focus via the global `:focus-visible`.
- **Alternatives:** legend region; a table of every unit with status and position (the text alternative to the drawing); node `role="img"` names.
- **Axe (Playwright, WCAG 2.0/2.1 A + AA tags), dev stack:** `/app/floor-plan` and the map with all six statuses on it → **0 violations at every impact level** (27 and 28 rules passing respectively), no baseline entry added. The gate itself only fails on serious/critical; I temporarily logged all impacts to report this, then reverted the logging.
- **Not claimed:** full WCAG conformance. Not tested: screen-reader behaviour, contrast measurement beyond axe, touch-device pinch, and dense layouts where node labels could overlap.

## 12. Test results

| Check | Result |
|---|---|
| Focused Vitest (7 new files) | 66 passed |
| Full Vitest, `--maxWorkers=2` | **388 / 388 passed** |
| Full Vitest, default parallelism | **intermittent**: the pre-existing flaky `app.test.tsx` landing-hero/sign-in tests (5 s timeout under load) failed in most default-parallelism runs and passed in one (388/388). Excluding all WP-C tests: 322/322. See §14 |
| `tsc -b` | clean |
| ESLint | 0 errors; 1 pre-existing warning (`a11y.spec.ts` unused disable) |
| Prettier | all source/e2e files pass; the 3 failures are pre-existing untracked artifact JSON |
| `npm run build` | success (6.4 s) |
| Playwright e2e `floor-plan.spec.ts` | 9 / 9 passed (admin ×4, technician ×2, teacher ×2, unauthenticated ×1) |
| Playwright a11y (floor-plan) | 2 / 2 passed |
| Pest (full, serialized) | **1082 passed**, 4171 assertions, 0 failed (1056 + 26 new) |
| Pint | PASS, 687 files |
| PHPStan level 6 | `[OK] No errors` |
| `migrate:status` (dev) | 0 pending; no migration file created |

## 13. Scope and integrity

- **Untouched:** WP-D (drag/drop, snapping UI, keyboard placement), WP-E (Reverb events), WP-F (persistence/versioning), AI/Gemini/RAG, notifications, production. `channels.php` unchanged.
- **WP-B unchanged:** no WP-B file appears in the WP-C diff.
- **21 pre-existing modifications:** byte-identical — diff hash `79c8b2b8…7024` and file-list hash `fdd1eb20…7ba` at session start, before commit and after commit. None staged.
- **Not staged:** `.env*`, `backups/`, `releases/` (two untracked entries from earlier sessions, untouched), `projectreports/`, `test-results`, `e2e/.artifacts`.
- **Production:** not touched; no production migration; no production containers restarted; no credentials generated.
- **Tools:** Read/Grep/Glob, git, Docker exec (Pest, Pint, PHPStan, Vitest, Playwright via `scripts/e2e.sh`, `artisan route:list`, `migrate:status`). Graphify, codebase-memory-mcp and claude-mem search were **not used** (indexes were flagged stale at session start and were not refreshed).
- **Dev database effect:** `scripts/e2e.sh` re-seeds the deterministic e2e fixtures (idempotent `updateOrCreate`), which now include the fixture room's layout and six `E2E-FP-*` PCs. Dev only.

## 14. Findings and deferred items

1. **Pre-existing flaky test made more likely.** `src/test/app.test.tsx` "renders the landing hero" sits near its 5 s timeout when Vitest runs 12 workers in this container (4.4–5.4 s under load; 1.4 s at `--maxWorkers=2`). Adding 66 tests raised the load enough to trip it in most default-parallelism runs. I did not touch the test or the config. Suggest a separate, deliberate fix (raise that test's timeout or cap workers) — owner's call.
2. **`PcStatus::tone()` collapses Available, Assigned and Retired to `neutral`.** On the map those three differ by shape and label only, not hue. Deliberate (WP-B), but `StatusPill` elsewhere draws Assigned in blue. Consider aligning if the client wants Assigned visually distinct.
3. **`StatusPill` is chosen by tone**, not by status, because it has no `retired` and its five keys do not match the six `PcStatus` values. A future `PcStatus` value needs a shape entry in `lib/presentation.ts` (typed `Record<PcStatusValue,…>`, so the compiler will say so).
4. **Grid density:** a very small `grid_size` on a large layout draws a dense grid with no level-of-detail step. Fine at today's sizes (20 px).
5. **Node labels can overlap** if two PCs are placed closer than a label width. Placement is WP-D's; a collision rule belongs there.
6. **Cross-feature import:** `FloorPlanPage` imports `useRoomsList` from `features/locations`. No precedent existed; a shared lookup would be cleaner if more features need it.
7. **`{room}` unbound on purpose** — future floor-plan routes must copy that, not use `{room:uuid}`.
8. **Docs still owed** (out of scope): SRS FR-FP-002/004/006 and §8.4 matrix, SDD §25 and `DD-*` entries, SPMP work-package marks, `backend/app/Domains/FloorPlan/README.md`.
9. **Dev DB still has Technician `floorplan.view`** (from WP-B, unchanged); harmless because the role is required.

## 15. Prerequisites for WP-D

1. Owner's separate go-ahead.
2. Decision **D3** (concurrency: optimistic `expected_updated_at` → 409 recommended; no migration) and **D5** (undo/redo: out of scope recommended).
3. Mutation routes gated by policy abilities (`manage` on `RoomLayout`/`FloorPlanPosition`), never `can:floorplan.*`; room and PC as unbound strings resolved through `RoomLayoutService`.
4. Use `RoomLayoutService::assertPcUnitInRoom()` and `assertEditable()`, and `CoordinateService::place()` for server-authoritative snap-then-clamp; add `isWithinBounds()` rejection (422) for out-of-range input.
5. Write `ActivityAction::AssetPositionChanged/Cleared` rows with before/after coordinates and layout version (no new table).
6. Frontend: node drag + a keyboard/numeric placement alternative (FR-FP-009), reconciling the optimistic UI to the server-snapped position; extend the viewport store only with view state.
7. Extend the same negative e2e/axe coverage to the edit surface; add Pest tests for cross-room placement, inactive layout (409) and per-user-grant refusal on every mutation route.
8. Reconsider the unresolved test-load flake (§14.1) before adding more Vitest files.

## 16. Git state after commit

- Branch `recovery/phase-2.7-restored-baseline`, HEAD `b845ea3`, one commit ahead of WP-B, 5 commits ahead of `origin` (`742ded5`).
- Tracked modifications: exactly the 21 pre-existing files, unchanged.
- Untracked: `projectreports/*`, `frontend/e2e/.artifacts/`, `frontend/test-results/`, `releases/2df17db/manifest.json`, `releases/current` — all pre-existing or generated, none staged.
- **Not pushed.**

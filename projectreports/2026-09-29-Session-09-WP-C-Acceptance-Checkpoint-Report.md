---
title: "Session 09 — WP-C Acceptance Checkpoint"
date: 2026-09-29
---

# Session 09 — WP-C Acceptance Checkpoint (read-only)

**Written for:** the project owner, as the acceptance record for WP-C.

## Decision: WP-C ACCEPTED — SAFE TO PUSH

Nothing was pushed, changed or staged by this checkpoint. HEAD is `b845ea3ffd03f2f7baf1add22987b862fb0d6200`; the live remote is still `742ded58…`.

## 1. Git integrity

| Check | Result |
|---|---|
| Branch | `recovery/phase-2.7-restored-baseline` |
| HEAD | `b845ea3ffd03f2f7baf1add22987b862fb0d6200` (WP-C) |
| Parent | `22607c1d39bf455b5890cfb0eb1572af3422798a` (WP-B) |
| `c9ab8a4`, `22607c1`, `742ded5` | all ancestors of HEAD |
| Live remote (`git ls-remote`) | `742ded58d0100b92419d9d83cf84cdc4f61dc6a6` |
| Local-only commits (WP-B and WP-C included) | 5: `98d857c`, `6ebb650`, `d2337bf`, `22607c1`, `b845ea3`; no remote-tracking ref contains WP-B or WP-C |
| WP-C commit | 37 files: 30 added, 7 modified; +2839 / −26 |
| Forbidden paths in WP-C (`.env*`, `backups/`, `releases/`, `migrations/`, reports, artifacts, manifests/locks, `channels.php`) | none |
| WP-C overlap with the 21 pre-existing modifications | none |
| 21 pre-existing modifications | byte-identical: list hash `fdd1eb201a10…`, diff hash `79c8b2b8ddca…`, identical at session start, after the tests, and at the end |
| Staged / stashed | 0 / 0 |

## 2. Scope audit (of the actual diff)

**Present:** `GET /api/admin/floor-plan/rooms/{room}`; `FloorPlanController`; `FloorPlanService`; `RoomPlan` DTO and `RoomPlanResource`; SVG map with `viewBox`; SVG `<pattern>` grid; PC nodes; pan; zoom; reset; toolbar; legend; Zustand viewport store; `RequireFloorPlan`; nav entry; per-status shape + tone + label; text alternative table; `StatusPill` reused; room picker.

**Absent (scanned added lines only):** node dragging, snapping, keyboard/numeric placement, any coordinate write, persistence, layout create/activate/version mutation, undo/redo, optimistic reconciliation, Reverb/Echo/broadcast, AI/RAG/assistant/predictive code, dependency or lock-file changes. The route diff adds exactly one `Route::get`. The only mutations in the commit are the idempotent `updateOrCreate` calls in the dev-only e2e fixture command (`sccit:e2e-fixtures`, which refuses to run in production).

An early scan of mine listed every pre-existing route because it read whole files; I discarded it and repeated the scan on added lines only.

## 3. WP-B boundary

- No WP-B file appears in the WP-C diff, and `git diff 22607c1 HEAD` over WP-B's file list is empty.
- `RoomLayoutService` remains the authorization layer: `FloorPlanService` calls `room()` and `activeLayout()` and adds no second copy.
- `FloorPlanAccess`: `isAdministrator() && hasPermissionTo('floorplan.view'|'floorplan.manage')`.
- `Gate::policy(RoomLayout…)` and `Gate::policy(FloorPlanPosition…)` remain explicit at `AppServiceProvider.php:117-118`.
- `CoordinateService` is unused by WP-C production code (verified by scan); WP-C never snaps or clamps.

## 4. Authorization

The single floor-plan route's actual middleware: `api | auth:sanctum | active | AuthenticateSession | password.current | can:viewAny,App\Models\RoomLayout`. No `can:floorplan.*` middleware exists; the remaining `floorplan.*` strings are the WP-B predicate, comments, the guard/nav UX check, and two pre-existing lookup-consumer lists (`PcUnitPolicy`, `RoomPolicy`) that only open narrow label lookups. A Pest test asserts no route under `floor-plan` uses a `can:floorplan.*` gate.

| Principal | API | Frontend |
|---|---|---|
| Administrator | 200 | map renders |
| Teacher | 403 | no nav entry; URL → Access denied |
| Technician | 403 | no nav entry; URL → Access denied |
| Unauthenticated | 401 | redirected to sign-in; no plan data |
| Technician / Teacher with per-user `floorplan.view` + `floorplan.manage` | **403** (Pest, grants proven effective first) | guard and nav still refuse (Vitest) |
| Administrator with per-user deny on `floorplan.view` | 403 | — |

Backend authorization is independent of the frontend: the Pest tests call the HTTP kernel directly, and Playwright hits the API as each role.

## 5. Route / IDOR

`{room}` is an unbound string: the controller passes it to `FloorPlanService`, which calls `RoomLayoutService::room()` after the `can` gate and the service's own `viewAny` check. A bound model would 404 before the 403. `whereUuid('room')` gives numeric/junk ids a router 404 with no database query; the service also guards with `Str::isUuid`. Tested: 403 for a real, unknown and archived room for Teacher/Technician; 404 for archived/unknown for Administrator; only the active layout serialized; another room's layout and PCs absent from the encoded payload; PCs that moved rooms, were never in the room, or are archived are dropped; historical versions are never returned.

Payload fields: room {id (uuid), name, code, floor{name, floor_number}, building{name, code}}, layout {version, width, height, grid_size}, pcs [{id (uuid), name, unit_code, status{value,label,tone}, x, y, rotation, z_index}], unplaced_count. **Not present:** numeric ids, serial numbers, IP, MAC, asset tag, hostname, QR identifier — asserted against the encoded response.

## 6. Frontend authorization

`RequireFloorPlan` = `RequireRole(['administrator'])` wrapping `RequirePermission('floorplan.view')`. No user, unknown role, non-administrator and administrator-without-permission all render the Forbidden page (8 tests). Navigation carries both `permission` and `roles` (4 tests). `FloorPlanRoomPage` checks for a 401/403 **before** reading plan data, so a stale cached success cannot render after a refusal; it does not retry 401/403/404. The Zustand store is populated only by `FloorPlanCanvas`, which mounts only from a successful payload.

## 7. Zustand

State keys: `zoom`, `x`, `y`, `content` (layout width/height) plus action functions — asserted by a test. No user, role, permission, room, PC or server state. Viewport maths is pure functions in `lib/viewport.ts` with no imports from auth or API code.

## 8. SVG / visualization

- Plain SVG; no canvas or map/gesture dependency (`package.json` unchanged; grep found none).
- `viewBox = x y W/zoom H/zoom` in layout pixels; a test asserts a stored `240.5, 80` renders as `translate(240.5 80)` with no conversion.
- Grid is an SVG `<pattern>` with a unique id per instance.
- Zoom clamped 50–400 %; pan keeps the window's centre over the layout; reset restores `0 0 W H`. All unit-tested.
- Node dragging pans the view and leaves the node's transform unchanged (Vitest and Playwright).
- Plain wheel does not zoom or trap scroll (Vitest); Ctrl/⌘ + wheel zooms.
- **Caveat:** the SVG has `touch-action: none`, so a one-finger swipe on the map pans it rather than scrolling the page on touch devices. See finding J.

## 9. Status and accessibility

Per status: tone colour (server), a distinct shape (circle, square, triangle, diamond, hexagon, dashed octagon), and a written label — on the node, in the legend, in the pill, in the table. Tests assert six distinct shape signatures, a label on every node, and that colour follows the server tone.

Accessibility present: node `role="img"` names with status and position; toolbar buttons with visible text and matching accessible names; zoom level in a `role="status"` live region; focusable map group with a description of its keys; global 3 px `:focus-visible` ring; legend region; table with caption and column headers as the text alternative; buttons disabled at zoom limits.

**axe (Playwright, WCAG 2.0/2.1 A + AA tags, dev stack):** `/app/floor-plan` and the map with all six statuses → both pass; a temporary full-impact log in the previous session showed 0 violations at every impact (27 and 28 rules passed). **Not claimed:** full WCAG conformance. **Untested:** screen-reader behaviour, measured contrast beyond axe, touch/pinch, reduced-motion, 200 % zoom/reflow, high-contrast mode, dense or overlapping labels.

## 10. Test results (all run this session)

| Check | Result |
|---|---|
| Focused Vitest (7 WP-C files) | 66/66, three consecutive runs |
| Full Vitest, default parallelism | intermittent — see §11 |
| Full Vitest, `--maxWorkers=2` | 388/388, two runs |
| `tsc -b` | exit 0 |
| ESLint | 0 errors; 1 warning (`a11y.spec.ts:77`, present in `22607c1` before WP-C) |
| Prettier `--check .` | only 3 untracked artifact JSON files flagged (`e2e/.artifacts/*`, `test-results/.last-run.json`); no tracked file |
| `npm run build` | success |
| Playwright e2e `floor-plan.spec.ts` (`--no-seed`) | 9/9 |
| Playwright a11y (floor-plan) | 2/2 |
| Focused Pest (FloorPlan + fixtures) | 131 passed |
| Full Pest (serialized) | **1082 passed**, 4171 assertions, 0 failed |
| Pint | PASS, 687 files |
| PHPStan level 6 | `[OK] No errors` |

## 11. The Vitest landing-hero / sign-in flake

Config: Vitest 4.1.11, Node 22, 12 CPUs, `vite.config.ts` sets `environment`, `setupFiles`, `css`, `include` only — **no** `pool`, `maxWorkers` or `testTimeout`.

| Configuration | Runs | Failures |
|---|---|---|
| Default parallelism, all 388 tests | 5 | 3 (hero ×3, sign-in ×1) |
| Default parallelism, **WP-C tests excluded** (original 322) | 5 | **4** (hero ×3, sign-in ×2) |
| `--maxWorkers=2`, all 388 | 2 | 0 (hero 1.4–1.6 s) |

- **Reproducible:** yes, on demand.
- **Only under default parallelism:** yes in this data.
- **Passing runs are marginal:** hero 4.5–4.8 s against a 5 s limit; the sign-in failure is `findByRole`'s default 1 s timeout at ~1.02 s.
- **WP-C tests pass consistently:** 66/66 in every run.
- **Pre-existing:** yes. It fails at least as often without WP-C, was documented during WP-2.7d (2026-08-31) and again in the 2026-09-28 dependency-remediation session (322 tests, no WP-C).
- **Correction:** the WP-C report said its 66 added tests made the flake much more likely. This sample does not support that; the claim came from too few runs and is withdrawn.
- **Not fixed** here, as instructed. Classified as a separate tracked finding.

## 12. Dev database and production

- No WP-C migration; 32 migration files in the repo, 32 ran, 0 pending; 0 migration files changed since `742ded5`.
- No production migration run, no production command executed this session.
- Production containers: all but `queue` share `StartedAt 2026-09-28T13:31:36Z`, as do all dev containers except `queue`. That is a Docker-engine restart of everything at once, during idle time between sessions — not an action of mine. Both queue containers restart hourly by design (`--max-time=3600`, exit 0, `restartPolicy=unless-stopped`; prod `restartCount=2`). Cause of the engine restart is not established from inside the repo.
- Dev fixtures: `sccit:e2e-fixtures` now also seeds an active layout and six `E2E-FP-*` PCs in the E2E lab (idempotent, dev only, refuses production). Not re-run this session (`--no-seed`).
- Dev role row `technician → floorplan.view` still exists (read-only query: `administrator: manage, view; technician: view`); 0 per-user `floorplan.*` overrides. It is seed state from before WP-B's withdrawal; `PermissionSeeder::$withdrawn` removes it when the seeder is next run, and the policy denies regardless.

## 13. Tools

Used: Read/Grep/Glob, git, Docker exec (Pest, Pint, PHPStan, Vitest, Playwright via `scripts/e2e.sh`, `artisan route:list`, `migrate:status`, read-only `psql`), `docker ps`/`inspect`. Not used: Graphify, codebase-memory-mcp, claude-mem search. The session-start hook reported Graphify built at `742ded58` and the codebase-memory index older than the newest source change; **both stale, not refreshed** (a refresh takes minutes, is not needed for a grep-verified checkpoint, and both are gitignored).

## 14. Findings classification

| # | Finding | Classification |
|---|---|---|
| A | Vitest landing-hero/sign-in timeout at default parallelism | **PRE-EXISTING / OUTSIDE WP-C** |
| B | `PcStatus::tone()` maps Available/Assigned/Retired to neutral | **ACCEPTED / DEFERRED** (shape + label carry the distinction) |
| C | `StatusPill` chosen by tone, not status | **ACCEPTED / DEFERRED** |
| D | Grid density for very small `grid_size` | **ACCEPTED / DEFERRED** |
| E | Possible PC label overlap | **REQUIRES WP-D** (placement collision rule) |
| F | `useRoomsList` imported from Locations | **ACCEPTED / DEFERRED** |
| G | Intentionally unbound `{room}` | **ACCEPTED / DEFERRED** (must be copied by every later floor-plan route) |
| H | Documentation drift (SRS FR-FP-002/004/006, permission matrix, SDD §25/DD, SPMP marks, FloorPlan README) | **DOCUMENTATION ONLY** |
| I | Dev DB still holds Technician `floorplan.view` | **PRE-EXISTING / OUTSIDE WP-C** |
| J | New: `touch-action: none` traps one-finger page scroll on touch devices (wheel and keyboard are not trapped) | **ACCEPTED / DEFERRED** — decide with WP-D's touch design (e.g. `pan-y` plus two-finger pan, or an explicit "pan mode") |
| K | New: sign-out clears only `registrations` and notification caches; floor-plan (and locations/assets/tickets) query caches survive sign-out in the same tab. Page is not reachable to a non-administrator, so nothing renders | **PRE-EXISTING / OUTSIDE WP-C** (fix: `queryClient.clear()` on logout) |

## 15. WP-D prerequisites (unchanged from the request, plus what this checkpoint adds)

1. Owner's separate go-ahead.
2. Decide **D3** (concurrency; optimistic `expected_updated_at` → 409 recommended, no migration) before writing any mutation route.
3. Decide **D5** (undo/redo; out of scope recommended).
4. Mutation routes gated by policy abilities (`manage`) — never `can:floorplan.*`; each must pass the structural route test.
5. Room and PC identifiers as unbound strings, resolved through `RoomLayoutService` after authorization.
6. `RoomLayoutService::assertPcUnitInRoom()` and `assertEditable()` on every write.
7. `CoordinateService::place()` for server-authoritative snap-then-clamp, and `isWithinBounds()` rejection (422) for out-of-range input.
8. `ActivityAction::AssetPositionChanged/Cleared` records with before/after coordinates and layout version.
9. No new table unless evidence proves one necessary.
10. Optimistic UI reconciled to the server-snapped coordinates.
11. A keyboard/numeric placement alternative to dragging (FR-FP-009).
12. Negative-authorization E2E coverage on the edit surface.
13. Cross-room placement tests; inactive-layout 409 test; per-user-grant refusal tests on **every** mutation route.
14. Resolve J (touch model) and E (label collisions) in the edit interaction design.
15. Address A before adding more Vitest files, or accept it as tracked.

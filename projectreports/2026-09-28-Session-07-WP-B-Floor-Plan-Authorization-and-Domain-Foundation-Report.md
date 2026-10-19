---
title: "Session 07 — WP-B: Floor Plan Authorization Boundary and Domain Foundation"
date: 2026-09-28
---

# Session 07 — WP-B: Floor Plan Authorization Boundary and Domain Foundation

**Written for:** the project owner, as the WP-B checkpoint report.

## 1. Status: PASS

WP-B is implemented and verified in the development stack. Nothing was pushed, and no production state was touched. Work stops here; WP-C is not started.

| Item | Value |
|---|---|
| WP-B commit | `22607c1d39bf455b5890cfb0eb1572af3422798a` |
| Local HEAD | `22607c1d39bf455b5890cfb0eb1572af3422798a` |
| Live remote HEAD (`git ls-remote`) | `742ded58d0100b92419d9d83cf84cdc4f61dc6a6` (unchanged — not pushed) |
| Recovery baseline `c9ab8a4` | remains an ancestor of HEAD |
| Migrations added | **0** |

## 2. Migration decision: zero migrations, `room_layouts.uuid` unnecessary

Evidence, from the live dev schema (`\d room_layouts`) and the migration source:

- `room_layouts` has `UNIQUE (room_id, version)` (`room_layouts_room_id_version_unique`) and a partial unique index `room_layouts_one_active_per_room`.
- `rooms` and `pc_units` already carry `uuid` route keys.
- A layout is therefore fully identified as **(room uuid, version)**. Resolving it *through its room* makes a mismatched room/layout pair unrepresentable: a version belonging to another room is simply not found.
- WP-B contains no HTTP endpoint that addresses a layout on its own, so nothing in this package needs a standalone layout identifier.

The earlier proposal (recon report §8.2, Migration A) was driven by route shapes such as `/layouts/{layout:uuid}/activate`. That is a WP-C/WP-F concern. If the owner later wants a bare layout uuid on the wire, that is a decision for that package; WP-B does not force it.

## 3. What was built

| Item | Result |
|---|---|
| `CoordinateService` | Pure `snap`, `clamp`, `isWithinBounds`, `place` (snap then clamp), `normalizeRotation`. Two-decimal results to match `decimal(10,2)`; no negative zero; non-finite input rejected. |
| `RoomLayoutService` | `room()`, `activeLayout()`, `layout()`, `pcUnit()`, `assertPcUnitInRoom()`, `assertEditable()`. Every read authorizes before touching the database. |
| `FloorPlanRuleViolation` | Self-rendering, `pc_unit_not_in_room` (422) and `layout_not_active` (409). |
| `PcStatus` | Explicit `label()` (identical strings to the previous derived default) and new `tone()`. |
| `ActivityAction` | `layout_created`, `layout_activated`, `layout_updated`, `asset_position_changed`, `asset_position_cleared`, with labels. |
| Policies | `RoomLayoutPolicy`, `FloorPlanPositionPolicy` — abilities `viewAny`, `view`, `manage`. |
| `FloorPlanAccess` | The single predicate both policies delegate to. |
| Registration | Both policies registered in `AppServiceProvider::registerAuthorization()` — the project's existing point; no second mechanism. |
| Permissions | `floorplan.view` removed from the Technician baseline list and added to `PermissionSeeder::$withdrawn['technician']`. |

Deliberately **not** built (out of scope): routes, controllers, resources, canvas or any UI, layout create/activate/versioning, position persistence, Reverb events.

## 4. `Gate::before` — actual behaviour and the correction

`AppServiceProvider` registers `Gate::before(fn (User $u, string $ability) => resolver->has($u, $ability) ? true : null)`.

- It can only **grant** (`true`) or **defer** (`null`); it never returns `false`.
- It grants when the ability *string* equals a permission in the user's effective set. Administrator holds every permission; Teacher and Technician hold none of `floorplan.*` after this change.
- Policy abilities `viewAny`/`view`/`manage` contain no dot and match no permission name, so `Gate::before` returns `null` and the policy runs. Confirmed by test: a per-user *deny* on `floorplan.manage` flips an Administrator's `manage` result — only an executing policy can do that.
- **The real risk:** `user_permissions` allows per-user grants (FR-USER-010). If an Administrator grants a Technician `floorplan.manage`, then `Gate::allows('floorplan.manage')` and any `can:floorplan.manage` route gate return **true** — `Gate::before` answers before any policy. This is pinned by a characterization test.
- **Correction (smallest possible):** `Gate::before` is unchanged. The floor-plan policies require the **Administrator role as well as the permission**. With the same per-user grants in place, Technician and Teacher remain denied by every policy ability and by `RoomLayoutService`.

## 5. Authorization evidence (real `Gate`, so `Gate::before`, policy resolution and the seeded matrix all participate)

| Principal | viewAny / view / manage on RoomLayout and FloorPlanPosition |
|---|---|
| Administrator | allowed |
| Teacher | denied |
| Technician | denied |
| Unauthenticated | denied (`Gate::allows` with no user) |
| Technician **with** per-user `floorplan.view` + `floorplan.manage` grants | policy abilities denied; bare string `floorplan.manage` allowed (documented trap) |
| Teacher with the same grants | denied |
| Administrator with per-user deny on `floorplan.view` | `view` denied, `manage` allowed |

A mutation check confirmed the tests bite: with the role requirement removed, exactly the 3 grant-bypass tests failed; the file was restored.

## 6. Direct-identifier (IDOR) evidence — `RoomLayoutServiceTest`

- Administrator: real room uuid, layout as (room, version), PC unit uuid in the room, and active layout all resolve.
- Teacher and Technician: the **same real identifiers** are refused with `AuthorizationException` on all four lookups.
- Refusal is identical for a real uuid, an invented uuid, and a malformed string — authorization precedes lookup, so there is no 404 oracle.
- A per-user floorplan grant does not open the service to a Technician.
- Layout of another room: not found. Same version number in two rooms resolves to the correct room's layout each time.
- PC unit of another room: not found via `pcUnit()`; refused with 422 `pc_unit_not_in_room` via `assertPcUnitInRoom()` in both directions; PC with no room is refused.
- Non-active layout: `assertEditable()` refuses with 409 `layout_not_active`. The database independently refuses a second active layout (`room_layouts_one_active_per_room`), asserted by constraint name.
- Nonexistent uuid, malformed uuid, SQL-shaped string, numeric id: not found, never a database error.
- Archived room and archived PC unit: not found.
- The service's actor parameter is a non-nullable `User`, so an unauthenticated caller cannot invoke it.

## 7. Permissions

- Technician `floorplan.view`: **withdrawn**. Verified two ways: fresh seed, and a database seeded with the old grant then re-seeded (`$withdrawn` detaches it).
- Technician permission set asserted exactly unchanged otherwise (15 permissions); Teacher set asserted exactly unchanged (7).
- `selectLocation` and `selectPcUnit` lookups still work for a Technician — `floorplan.view` was only one of several permissions that open them.

## 8. Layers

| Layer | Status |
|---|---|
| Route / controller | **None exist.** Floor-plan routes are WP-C. Nothing to protect yet; no dead route was added. |
| Policy | Implemented, registered, executed. |
| Service | `RoomLayoutService` authorizes before every read. |
| Direct identifier | Covered (§6). |
| Resource serialization | **No floor-plan resource exists yet.** `PcStatus` label/tone is the only serialized-adjacent addition. |
| Frontend | **No change.** There is no in-app floor-plan route, page, nav item or `floorplan.*` reference (only marketing copy). No frontend files changed, so frontend gates were not re-run. |

## 9. Tests

| Check | Result |
|---|---|
| Focused Pest (`tests/Unit/FloorPlan`, `tests/Feature/FloorPlan`) | 99 tests, all pass after one test-side fix (§10) |
| Full Pest suite, serialized | **1056 passed, 4071 assertions, 0 failed** (957 baseline + 99 new) |
| Pint `--test` | PASS, 681 files (one import-order issue in a new test file fixed) |
| PHPStan level 6 | `[OK] No errors` |
| `migrate:status` (dev) | 0 pending |
| TypeScript / ESLint / Vitest / build | Not run — no frontend file changed |

## 10. Findings

1. **`floorplan.*` per-user grants bypass any permission-string gate.** Documented in `FloorPlanAccess`, the `AppServiceProvider` comment, and a characterization test. **WP-C routes must authorize through policy abilities** (for example `can:viewAny,App\Models\RoomLayout`), never `can:floorplan.manage`.
2. **The seeder does not invalidate `PermissionResolver` caches.** `PermissionSeeder` detaches the role grant but never calls `forget()`. Cached effective sets (TTL 3600 s, Redis) keep `floorplan.view` for up to an hour after a deploy-time re-seed. The policy role check makes this harmless for the floor plan; it is a pre-existing gap for any future withdrawal.
3. **Dev database still holds Technician `floorplan.view`** (1 role_permissions row, read-only inspection). Not changed: `$withdrawn` only runs when the seeder runs. Running `PermissionSeeder` on dev converges it (dev-only, idempotent); it is a deploy-time step for production and was not run there.
4. **Test-side stale relation.** One of my tests initially failed because `PermissionResolver::compute()` uses `loadMissing('role.permissions')`, so a reused `User` instance keeps its old role relation across a re-seed. Fixed in the test by re-fetching the user; not a production defect (each request loads fresh).
5. **`CoordinateService::place()` can return an off-grid far-edge value** when the canvas size is not a multiple of the grid (clamp follows the recon's specified snap-then-clamp order). Documented in the method and pinned by test.
6. **Stale README:** `backend/app/Domains/FloorPlan/README.md` still says "FUTURE MODULE (do NOT implement yet)". Left for the documentation reconciliation.
7. **SRS FR-FP-006 and the §8.4 permission matrix** still show Technicians with `floorplan.view`. Documentation reconciliation is out of scope here and remains owed.
8. **Index refresh owed:** Graphify and codebase-memory were stale at session start and were **not** used or refreshed this session.

## 11. Integrity

| Check | Result |
|---|---|
| Production state changed | No |
| Production migration run | No (the two pending production notification migrations were not applied) |
| Production Reverb credentials generated | No |
| `backend/.env.production`, `backups/prod/*` | Not touched, not staged |
| Production containers | Not restarted or recreated; only the dev `app` container was exec'd into |
| The 21 pre-existing tracked modifications | Byte-identical before and after: `git diff` over those 21 files hashes to `79c8b2b8…7024` at session start and after commit; file-list hash `fdd1eb20…7ba` likewise |
| Files staged | 14 — all WP-B; none of the 21; no env, backup or secret files |
| Push | None |

**Tools honestly used:** Read/Grep/Glob, git, Docker exec for Pest/Pint/PHPStan/psql (read-only). Graphify, codebase-memory-mcp and claude-mem search tools were **not** used this session (indexes were flagged stale).

## 12. Stop

WP-B is complete. Awaiting a separate instruction before anything further; the GREEN production gate is not treated as authorization for WP-C or later.

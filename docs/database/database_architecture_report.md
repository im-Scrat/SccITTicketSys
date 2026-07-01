# Database Architecture Report — v1 → v2

**System:** AI-Powered School IT Asset & Service Management (ITAM + ITSM)
**Stack:** PostgreSQL 17 · Laravel 13 · PHP 8.4 · pgvector
**Source of truth:** `docs/database/database_design_v2.dbml`
**Companion:** `docs/database/database_design_review.md` (accepted assessment)
**Date:** 2026-06-30
**Status:** 🟦 Design complete — **awaiting approval. No migrations generated.**

---

## 1. Executive Summary

v2 re-architects the schema from a solid-but-draft design into a production-grade enterprise ITAM/ITSM
data model. The conceptual business model is **unchanged** — every change is either an objective
database-engineering best practice or one of four structural decisions you explicitly approved.

**Headline outcomes:**
- **Timezone-correct** by construction — every datetime is `timestamptz` (was naive `timestamp`),
  the prerequisite for trustworthy analytics and SLA reporting.
- **Referentially safe** — all ~120 foreign keys now have explicit `ON DELETE`/`ON UPDATE` policy, and
  business/master entities are **soft-deleted**, so history is never silently destroyed.
- **Performant by default** — every FK column is indexed, plus composite, partial, BRIN, GIN (full-text)
  and HNSW (vector) indexes for the real query patterns.
- **AI/RAG-ready** — a real pgvector embedding store (`ai_embeddings`) replaces a status-only stub, so
  the Gemini + RAG layer drops in without a schema rewrite.
- **Floor-plan-ready** — a proper `buildings → floors → rooms → room_layouts → floor_plan_positions`
  hierarchy with offices, snap-to-grid, and versioned, layout-scoped positions.
- **Reporting-ready** — ticket lifecycle timestamps, status `is_open`/`is_terminal` flags, SLA fields,
  and explicit status-history tables make MTTR/SLA/backlog metrics first-class.
- **Cleaner inventory** — serialized `assets` separated from quantity-based `consumables`; location
  normalized to `rooms`; procurement gains a real approval trail.

Table count grew (~37 → ~70) by deliberate design — per your "do not optimize for fewest tables"
directive, lookup/bridge/history tables were added where they produce a cleaner, more normalized model.

---

## 2. Every change made & why

### 2.1 The four approved structural decisions

| # | Decision | What changed | Why |
|---|----------|--------------|-----|
| D1 | **Split inventory** | New `assets` (serialized, 1 row = 1 unit, lifecycle) + `consumables` (quantity, reorder level). `stock_transactions` now references `consumables`; `pc_component_installations` and `hardware_replacements.new_asset_id` reference `assets`. Added `asset_status_history`. | v1 conflated serialized assets with bulk stock. The split is the standard ITAM model (Snipe-IT/ServiceNow) and makes both quantity tracking and per-unit lifecycle correct. |
| D2 | **Clarify hardware catalog** | `hardware_components` kept as the **generic type** (now `manufacturer_id` FK instead of free-text), `hardware_models` kept as the **specific SKU** (`hardware_component_id` + `specifications jsonb`). `hardware_replacements` still references components, plus a new `new_asset_id` to the physical part. | Removes the free-text manufacturer duplication and formally separates "type" from "SKU" without merging entities you wanted to keep. |
| D3 | **Generalize locations** | `laboratories` → `rooms` (with `room_type`: lab/office/storage/server_room/…); new `floors` between buildings and rooms; `laboratory_layouts` → `room_layouts` (+ `grid_size`, `version`, `is_active`); positions moved off `pc_units` into `floor_plan_positions` (layout-scoped). All former `laboratory_id` FKs now `room_id`. | Matches the brief's Buildings/Floors/Labs/Offices and "add floors/rooms" admin actions, and makes the Interactive Floor Plan addable "without major refactoring." |
| D4 | **Role-neutral reporter** | `tickets.teacher_id` → `tickets.reporter_id`. | Technicians/admins can legitimately raise tickets; the schema no longer encodes a single role. |

### 2.2 Objective best-practice changes (applied without asking, per your mandate)

**Typing & correctness**
- All datetimes → **`timestamptz`** (every table).
- Money → **`numeric(12,2)`**; confidence/probability/score → **`numeric(5,4)`**; coordinates →
  `numeric(10,2)`; geo → `numeric(9,6)`. (v1 used unparameterized `decimal`.)
- IP columns → **`inet`**; `users.email`, supplier/manufacturer emails → **`citext`** (case-insensitive).
- Loose/structured text → **`jsonb`**: `audit_logs.old/new_values`, `system_settings.value`,
  `dashboard_widgets.configuration`, `hardware_models.specifications`, AI `raw_response`/`metadata`/
  `payload`, `activity_logs.properties`, `notifications.data`.

**Keys, identity & audit**
- **External `uuid`** (unique, `gen_random_uuid()`) on every user-facing entity → kills ID enumeration / IDOR.
- **Soft deletes** (`deleted_at`) on business/master entities (users, buildings, floors, rooms, pc_units,
  assets, consumables, tickets, comments, maintenance_records, procurement, knowledge_articles,
  announcements, suppliers, manufacturers, catalog) — never on logs/history/pivots.
- **Blame columns** (`created_by`/`updated_by`) on key mutable entities.
- **Lifecycle timestamps** on `tickets` (`first_response_at`, `resolved_at`, `closed_at`, `reopened_at`,
  `response_due_at`, `resolution_due_at`) and on maintenance/assignment/procurement.

**Integrity**
- Explicit **`ON DELETE` / `ON UPDATE`** on every FK (restrict / cascade / set null policy — §2.3).
- New **unique constraints**: `ticket_votes(ticket_id,user_id)`, `pc_specifications.pc_unit_id`,
  `floors(building_id,floor_number)`, `floor_plan_positions(room_layout_id,pc_unit_id)`,
  catalog `(manufacturer_id,name)` / `(hardware_component_id,model_name)`,
  `ai_feedback(ai_recommendation_id,user_id)`, embeddings/sources uniqueness, lookup `name`/`slug`.
- **CHECK constraints** documented per table: score ∈ [0,1]; non-negative quantities/sizes/costs;
  no self-reference (`duplicate_of_id <> id`, `parent_comment_id <> id`); date ordering
  (`ends_at > starts_at`, `warranty >= purchase`); geo bounds; hex color; QR exactly-one-target.
- **Enum domains** for ~35 state/type columns (varchar + CHECK + PHP enum cast), while
  user-configurable color-coded domains stay as **lookup tables**.

**Naming consistency**
- Reference tables use `name` + `slug`/`code` (`building_name` → `name`, etc.).
- FK columns get `_id` suffixes (`created_from_ticket` → `created_from_ticket_id`,
  `active_model` → `active_model_id`, `duplicate_ticket_id` → `duplicate_of_id`).
- Log "body" columns standardized (`description` → `body` on updates/comments/notes).

**Removed redundancy**
- Dropped `tickets.is_duplicate` (derivable from `duplicate_of_id IS NOT NULL`).
- Replaced `qr_scan_logs.qr_code` (loose varchar) with `qr_code_id` FK.
- Removed `pc_units.pos_x/pos_y` (moved to `floor_plan_positions`).

**New supporting tables** (for a cleaner enterprise model): `floors`, `room_layouts`,
`floor_plan_positions`, `tags`, `ticket_tags`, `checklist_templates`, `checklist_template_items`,
`asset_status_history`, `notification_preferences`, `ai_embeddings`, plus framework auth tables
(`sessions`, `password_reset_tokens`, `personal_access_tokens`) included for completeness.

### 2.3 ON DELETE policy (rationale)
- **RESTRICT** on reference/lookup parents (roles, ticket lookups, maintenance_types, manufacturers,
  hardware catalog, ai_models) and on not-null human actors who own records (`reporter_id`,
  `technician_id`, `requested_by`) — you must reassign/soft-delete, not orphan.
- **CASCADE** on true compositions (a ticket's updates/history/votes/comments/attachments/tags; a
  maintenance record's checklists/images/notes/replacements; request items; role/user permissions;
  layout positions; pc_specifications).
- **SET NULL** on optional context (`tickets.pc_unit_id/room_id/assigned_technician_id/duplicate_of_id`,
  `maintenance_records.ticket_id`) and on nullable audit/blame actors — history survives the parent.

---

## 3. PostgreSQL best practices applied
- `timestamptz` everywhere; `numeric` (never float) for money; `inet`/`citext` native types.
- `jsonb` (not `json`/`text`) for semi-structured data, GIN-indexable.
- **pgvector**: `vector(768)` column + **HNSW** (`vector_cosine_ops`) index for ANN search.
- **Partial indexes** for hot predicates (one active room layout, one active assignment per ticket,
  unread notifications, one active install per asset, single default status).
- **BRIN indexes** on append-only logs' `created_at` (audit/activity/scan/login/stock) — tiny, ideal for
  time-range scans.
- **GIN full-text** (generated `tsvector`) on `tickets` and `ai_knowledge_articles`.
- **Composite indexes** matching real access paths (status+created_at, technician+status, pc+date…).
- Explicit FK actions; `num_nonnulls()` CHECKs for "exactly/at-least one of" target columns.
- Audit table hardened append-only (revoke UPDATE/DELETE or trigger).
- `gen_random_uuid()` (pgcrypto/core) for UUID defaults.

## 4. Laravel best practices applied
- `bigint` identity PKs (`bigIncrements`), `timestamptz` via `$casts`/`timestamptz` columns.
- `SoftDeletes` trait alignment (`deleted_at`); `created_by`/`updated_by` via an Auditable/Blameable trait.
- String-backed **PHP enums** mirror every DB CHECK domain (single source of truth in `app/Enums`).
- Lookup tables → seeders; volatile data → factories.
- Sanctum SPA tables (`sessions`, `personal_access_tokens`, `password_reset_tokens`) reconciled with the
  default `users` migration (resolving the v1 divergence).
- Polymorphic-friendly columns (`auditable_*`, `subject_*`, `embeddable_*`) map to morph relations.
- UUID route-key columns for `Route::bind` on public resources.
- Counter caches (`upvote_count`, …) maintained via model observers/events.

## 5. Scalability improvements
- Append-only high-volume tables (`audit_logs`, `activity_logs`, `ai_conversation_logs`, `qr_scan_logs`,
  `login_history`, `stock_transactions`) are shaped for **declarative range partitioning by `created_at`**
  (keys chosen now; partition later) with BRIN indexes in the interim.
- Counter caches keep list/feed reads O(1) instead of aggregating.
- Vector search isolated in `ai_embeddings` with HNSW so RAG scales independently of OLTP tables.
- `bigint` keys throughout; UUIDs are secondary (no UUID-PK index bloat).

## 6. Performance improvements
- **Every FK indexed** (Postgres doesn't auto-index the child side) — the single biggest win for joins
  and cascade performance.
- Composite/covering indexes for dashboards, "my tickets", PC history, unread badges.
- `pc_specifications` retained as a fast 1:1 read snapshot; structured truth in installations.
- Full-text indexes remove `LIKE '%…%'` scans on tickets/KB.
- Partial indexes keep hot subsets small.

## 7. Security improvements
- **UUID external identifiers** close IDOR/enumeration on `/api` resources.
- `password NOT NULL`; `email_verified_at`/`remember_token` present; `last_login_ip` as `inet`.
- **Append-only, tamper-resistant `audit_logs`** with jsonb diffs.
- PII concentrated and soft-deletable; attachment `checksum` + stored outside web root + mime/size CHECKs.
- `system_settings.is_public` gates which settings may reach the frontend.
- `user_permissions.grant_type` supports explicit **deny** overrides.

## 8. AI / RAG readiness
- **`ai_embeddings`**: `vector(768)`, polymorphic source, `chunk_index`, `content`, `content_hash`,
  `ai_model_id`, HNSW index — the actual RAG store that v1 lacked.
- **`ai_embedding_sources`**: indexing/staleness tracker (pending/indexed/stale/failed) decoupled from vectors.
- `ai_models` registry with `modality` + `embedding_dimensions` (Gemini-ready); `ai_system_settings`
  separates chat model from embedding model.
- Token/latency columns on analysis & conversation logs for cost/observability.
- Knowledge articles carry status + FTS + embeddings for retrieval.
- Structured `pc_component_installations` + `ai_failure_patterns` (now also by `hardware_component_id`)
  feed predictive maintenance.

## 9. Interactive Floor Plan readiness
- Full spatial hierarchy: `buildings → floors → rooms(room_type) → room_layouts → floor_plan_positions`.
- `room_layouts.grid_size` (snap-to-grid), `width/height` (canvas), `version` + active-layout partial unique.
- Layout-scoped positions support PCs moving rooms and multiple/versioned layouts; `rotation`/`z_index`
  for rich rendering; real-time drag writes to one bridge table.
- `pc_units.status` enum matches the required icon colors (available/assigned/online/offline/
  under_maintenance/retired); `mac_address`/`ip_address` reserved for future network topology.
- Info-panel data (specs, installed hardware, QR, repair/upgrade history, AI prediction, tickets,
  timeline) all reachable via indexed FKs to one `pc_unit`.

## 10. Reporting & analytics readiness
- Ticket `first_response_at`/`resolved_at`/`closed_at` + status `is_open`/`is_terminal` → MTTR, FRT,
  backlog, reopen rate.
- SLA via `ticket_priorities.response_time_minutes`/`resolution_time_minutes` → `tickets.*_due_at`
  (breach reporting).
- `timestamptz` makes time-bucketed reports correct across DST.
- Dedicated history tables (`ticket_status_history`, `asset_status_history`, `stock_transactions`,
  `pc_component_installations`) give clean event streams; `downtime_minutes`/`labor_hours`/`cost` on
  maintenance support cost/uptime analytics.
- Designed for later **materialized views** (MTTR, asset failure rates, inventory valuation).

---

## 11. Remaining recommendations for future phases
1. **Partitioning**: convert the five append-only log tables to declarative monthly partitions once
   volume warrants (keys already aligned).
2. **Materialized views** for the analytics/report module (refresh on schedule).
3. **Tickets full-text & embeddings pipeline**: queue jobs to populate `tsvector` + `ai_embeddings`.
4. **MFA columns** on `users` when 2FA is scheduled.
5. **Network topology tables** (`network_interfaces`, `device_connections`) when that future map ships.
6. **Saved/scheduled reports** (`report_definitions`, `scheduled_reports`) for the reporting module.
7. **Retention policies** for logs and PII (scheduled prune jobs).
8. **Row-Level Security**: not needed (single-tenant school); revisit only if multi-school.

---

## 12. Architectural decisions that still require human approval

These are choices I made in v2 that are reasonable defaults but are worth your explicit sign-off before
migrations, because changing them later is more costly:

1. **Embedding dimension = 768.** Matches Gemini `text-embedding-004`. If you'll use
   `gemini-embedding-001` at 1536/3072, say so — 3072 exceeds pgvector's ~2000-dim index limit and would
   change the indexing strategy.
2. **Custom `notifications` table** (vs Laravel's generic polymorphic `notifications`). I chose a richer
   in-app notification-center shape. Confirm, or switch to the framework-standard table.
3. **SLA modeled on `ticket_priorities`** (response/resolution minutes) rather than a separate
   `sla_policies` table. Adequate now; a dedicated table would support per-category/per-customer SLAs later.
4. **Single role per user** (`users.role_id`) retained, with `user_permissions` for overrides. If a user
   may hold multiple roles, we'd add a `role_user` pivot (a conceptual change — flagged, not applied).
5. **`pc_units` kept separate from `assets`.** A PC is modeled as a composed CI, not an `assets` row.
   Alternative: make every PC also an `asset`. I recommend keeping them separate (simpler, no dual
   identity); confirm.
6. **Soft-delete scope.** Applied to business/master entities, not to lookups or logs. Confirm the scope
   (e.g., should `ticket_categories` be soft-deletable too?).

---

## 13. Next step

**STOP — awaiting your approval.** No Laravel migrations, seeders, or factories have been generated.

On your **approve**, I will generate the complete database layer **directly from
`database_design_v2.dbml`** — migrations (in dependency order), FKs, indexes, unique + CHECK constraints,
enum casts, justified triggers (counter caches, audit append-only, `updated_at`), seeders, factories,
lookup/reference/pivot tables — then verify via `migrate:fresh` → `rollback` → `migrate`, checking FKs,
indexes, constraints, seeders, factories, and ordering, auto-fixing until the layer is production-ready.

If you'd like to change any of the §12 items first, tell me which and I'll revise v2 before we proceed.

---

## 14. Addendum — Centralized `system_settings` (post-approval request)

**Confirmed: a centralized configuration table exists** (`system_settings`) and has been **enhanced** to
serve as the single global application-configuration store:

- **Shape:** `group`, `key` (unique), `label`, `value jsonb`, `type` (string|integer|boolean|float|
  json|array), `description`, `is_public`, `is_protected`, `created_by`, `updated_by`, timestamps.
- **Flexible by design:** the `jsonb value` + `type` pair lets any current/future global config evolve
  **without schema changes** — School Name/Info, Academic Year, Semester, maintenance intervals, AI
  config, email config, QR defaults, Interactive Floor Plan defaults, branding/logo/theme, dashboard
  config, notification defaults, and general preferences all live here keyed by `group`.
- **Frontend safety:** `is_public` marks settings safe to expose (e.g. `school_name`, `logo`, `theme`);
  secrets (e.g. email credentials) stay server-side.
- **Integrity:** `is_protected` flags seeded system-critical keys that must not be deleted.
- **Admin-only authorization (clean RBAC):** create/update/delete is gated by a seeded
  `system.settings.manage` permission assigned only to the Administrator role, enforced by a
  `SystemSettingPolicy` in Phase 2. Teachers/Technicians may read `is_public` settings only. This reuses
  the existing `roles` / `permissions` / `role_permissions` / `user_permissions` model — no new
  mechanism required.
- **Relationship to `ai_system_settings`:** general/global key-value config lives in `system_settings`;
  strongly-typed AI **runtime** config with model FKs (active/embedding model, thresholds, feature
  toggles) remains in `ai_system_settings`. Both are admin-managed.

No conceptual change — this is an enhancement of an existing table. Proceeding to generate the database
layer.


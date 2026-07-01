# Database Architecture Review — `database_design.dbml`

**Reviewer role:** Senior PostgreSQL Database Architect / Enterprise Software Architect
**Subject:** `docs/database/database_design.dbml` (authoritative design)
**Project:** AI-Powered School IT Asset & Service Management System (Laravel 13 · PostgreSQL 17 · pgvector)
**Date:** 2026-06-30
**Status:** Review only — **no migrations generated, no schema changes made.** Awaiting approval.

---

## 0. How to read this document

This is a design-stage review of the schema *before* any migration is written. Findings are
graded:

- **🔴 Critical** — will cause data loss, corruption, security exposure, or a forced re-architecture later.
- **🟠 Important** — meaningfully hurts performance, integrity, or a named future module; fix before go-live.
- **🟡 Advisory** — best-practice / polish; safe to schedule.

The schema is genuinely good for a pre-implementation draft: 60+ tables, clean module separation,
real history/audit tables, and lookup tables already in the right places. The issues below are about
hardening it to the "production, real client" bar the brief sets — they are **not** a rewrite.

---

## 1. Strengths

1. **Sound module decomposition.** Core / Ticketing / Maintenance / Inventory / AI / Administration
   map cleanly onto the existing `backend/app/Domains/*` seams (Tickets, Assets, Maintenance,
   KnowledgeBase, Analytics, FloorPlan). The schema is organized the way the code is.
2. **Lookups done right.** `ticket_categories`, `ticket_priorities`, `ticket_statuses`,
   `maintenance_types` are reference tables rather than hard-coded enums — correct, because the brief
   requires user-configurable, **color-coded** statuses/priorities. Keep these as tables.
3. **First-class history & audit.** `ticket_status_history`, `ticket_updates`, `audit_logs`,
   `activity_logs`, `login_history`, `stock_transactions`, `pc_component_installations` give real
   temporal traceability — exactly what an asset-management system needs.
4. **Proper M:N modeling.** `role_permissions` / `user_permissions` use composite-PK join tables.
   RBAC (roles) + per-user overrides (`user_permissions`) is a mature pattern.
5. **AI module is anticipated, not bolted on.** Dedicated `ai_*` tables (models, analysis logs,
   recommendations, conversations, predictions, failure patterns, knowledge articles, feedback) plus
   an embedding-tracking table show the RAG future was considered. `pgvector` is already enabled in
   `docker/postgres/init/01-enable-extensions.sql`.
6. **Floor-plan groundwork exists.** `buildings`, `laboratories`, `laboratory_layouts`, and
   `pc_units.pos_x/pos_y` are present, matching the future Interactive Floor Plan.
7. **Lifecycle coverage in inventory.** Procurement → stock → installation → transfer → disposal is a
   complete asset lifecycle, which most school systems never model.
8. **Consistent surrogate keys.** `bigint` identity PKs everywhere — the right default for Laravel.
9. **Intentional read-optimization.** Counter caches on `tickets` (`upvote_count`, `comment_count`,
   `attachment_count`) show performance was considered up front.

---

## 2. Weaknesses (cross-cutting)

These apply broadly and are the highest-leverage fixes.

### W1 🔴 `timestamp` instead of `timestamptz` everywhere
Every datetime column is `timestamp` (without time zone). PostgreSQL best practice — and Laravel's UTC
convention — is **`timestamptz`**. With plain `timestamp`, DST/timezone handling silently corrupts
reporting (MTTR, SLA, scan times, login history). **Make every datetime `timestamptz`.** This is the
single most important change and is nearly free to apply now.

### W2 🔴 No soft deletes and no defined FK delete behavior
No table has `deleted_at`, and the DBML `Ref:` lines specify no `ON DELETE` / `ON UPDATE` actions.
Deleting a `user`, `pc_unit`, `laboratory`, or `ticket` that is referenced by history/audit rows will
either fail or orphan data depending on the default. For a system whose whole value is history, you
must decide referential actions deliberately:
- **RESTRICT** for reference data (`roles`, `*_categories`, `*_statuses`, `manufacturers`, `suppliers`).
- **SET NULL** for optional context FKs (`tickets.pc_unit_id`, `tickets.laboratory_id`).
- **CASCADE** only for true children that have no meaning without the parent (e.g.
  `procurement_request_items` → `procurement_requests`, `pc_specifications` → `pc_units`).
- **Soft delete (`deleted_at`)** on master entities (`users`, `pc_units`, `laboratories`,
  `buildings`, `tickets`, `inventory_items`) so referenced rows are never physically removed.

### W3 🔴 Foreign-key columns are not indexed
The DBML declares only PKs and `unique`s. **PostgreSQL does not auto-create an index on the
referencing side of an FK** (only the referenced PK is indexed). Every FK used in a join or filter —
and there are ~80 of them — needs its own index, or every lookup and every cascade does a sequential
scan. See §11 for the concrete list. This is the biggest *performance* gap.

### W4 🟠 No `created_by` / `updated_by` blame columns on master entities
Audit tables capture row diffs, but core mutable entities (`buildings`, `laboratories`, `pc_units`,
`inventory_items`, `ticket_categories`, …) don't record *who* created/changed them. For a multi-admin
production system, add `created_by` / `updated_by` (FK → `users`) on the key mutable masters.

### W5 🟠 Sequential `bigint` IDs are the only external identifier (IDOR / enumeration)
User-facing resources (`tickets`, `pc_units`, `users`) expose guessable sequential IDs over the REST
API. Add an indexed, unique **`uuid`/`ulid`** column for external references on user-facing entities,
and route on that. Keeps `bigint` PKs internally (fast joins) while closing enumeration/IDOR.

### W6 🟠 `text`/`varchar` used where `JSONB` / typed columns belong
- `audit_logs.old_values` / `new_values` → **`jsonb`** (queryable diffs, GIN-indexable).
- `dashboard_widgets.configuration`, `system_settings.setting_value`, `hardware_models.specifications`
  → **`jsonb`**.
- `*.ip_address` (`activity_logs`, `login_history`, `audit_logs`) → **`inet`**.
- `decimal` money columns are unparameterized → **`numeric(12,2)`**; confidence/probability →
  `numeric(5,4)`; coordinates → defined precision.

### W7 🟡 `varchar` columns have no length and many missing `not null`
Most `varchar` have no length cap and most non-key columns omit `not null`. Decide deliberate
`NOT NULL` on columns the app always requires (names, codes, `tickets.title`, `users.password`,
status/priority FKs on `tickets`). Length caps protect against abuse and document intent.

---

## 3. Normalization review

Overall the schema is close to **3NF**, with intentional, defensible denormalization (counter caches,
spec snapshots). The real normalization concerns are a few overlapping models:

### N1 🟠 `hardware_components` vs `hardware_models` vs `inventory_items` overlap
Three tables describe "a piece of hardware":
- `hardware_components` (`component_name`, `component_type`, **`manufacturer` varchar**, `model`)
- `hardware_models` (`manufacturer_id` → `manufacturers`, `component_type`, `model_name`, `specifications`)
- `inventory_items` (a physical, serialized unit referencing `hardware_models`)

`hardware_components.manufacturer` and `.model` are free-text duplicates of the normalized
`manufacturers` / `hardware_models` data. `hardware_replacements` references `hardware_components`,
while `pc_component_installations` references `inventory_items` — **two parallel models of the same
real-world thing**. Recommendation: collapse `hardware_components` into `hardware_models` (catalog) and
have `hardware_replacements` point at the **physical `inventory_item`** actually fitted (and at its
`hardware_model` for the catalog view). This also closes the inventory/maintenance integration gap
(§4, M-gap).

### N2 🟠 Serialized-asset vs quantity-based inventory are conflated
`inventory_items` looks **serialized** (`item_code [unique]`, `serial_number`, `barcode`, 1 row = 1
unit), yet `stock_transactions.quantity` and `procurement_request_items.quantity` imply **bulk/
consumable** stock. A specific SSD (serialized) and thermal paste (consumable) cannot share one model
cleanly. Decide explicitly: either (a) split `assets` (serialized) from `consumables` (quantity), or
(b) add an `is_serialized` flag + `quantity_on_hand` and make `stock_transactions` meaningful only for
non-serialized items. This affects the whole inventory module and is best resolved now.

### N3 🟡 QR identity is modeled three ways
`pc_units.qr_identifier [unique]`, the `qr_codes` table (`qr_code [unique]`), and
`qr_scan_logs.qr_code` (a loose `varchar`, **not** an FK). The scan log should reference `qr_codes.id`
(or `pc_unit_id`), and the relationship between `pc_units.qr_identifier` and `qr_codes.qr_code` should
be made explicit (which is canonical?). Today a scan can't be reliably joined back to the QR record.

### N4 🟡 Location stored as free text in several places
`inventory_items.current_location`, `asset_transfers.from_location` / `to_location` are `varchar`,
duplicating the structured `buildings`/`laboratories` hierarchy. Consider FKs to a location entity (or
a polymorphic location) so transfers and asset locations are joinable and reportable.

### N5 🟡 Defensible denormalization — keep, but document & protect
- `tickets.upvote_count` / `comment_count` / `attachment_count` — counter caches. Keep, but they must
  be maintained transactionally (triggers or app events) or they drift.
- `tickets.ai_summary` / `ai_confidence` duplicate `ai_analysis_logs` — a "latest result" snapshot.
  Keep, but name the source of truth (the log table is history; the ticket columns are the cache).
- `pc_specifications` (free-text specs) duplicates structured `pc_component_installations`. Keep as a
  fast read snapshot, but treat `pc_component_installations` as authoritative for AI/failure analysis.
- `tickets.is_duplicate` is fully derivable from `duplicate_ticket_id IS NOT NULL` — **redundant
  boolean**; either drop it or keep only as a maintained flag. (Listed again in §6.)

---

## 4. Missing entities (tables)

| # | Table | Grade | Why it's needed |
|---|-------|-------|-----------------|
| M1 | `floors` | 🟠 | Brief explicitly lists "Multiple floors" and "Add floors" as a distinct admin action. Today `laboratories.floor` is a bare `int`. The Floor Plan module needs `buildings → floors → rooms`, with per-floor background images/layouts. Without it, multi-floor plans force a refactor — exactly what the brief says to avoid. |
| M2 | `rooms`/`spaces` (or `offices` + `room_type`) | 🟠 | Brief lists **Offices** alongside Laboratories as floor-plan locations. Only `laboratories` exists. Generalize to a `rooms` table with `room_type` (lab/office/storage), or add `offices`. PC units and layouts should attach to a room, not specifically a lab. |
| M3 | **Embeddings / vector store** (`ai_embeddings` with `vector` column) | 🔴 | `pgvector` is enabled but **no table has a `vector` column**. RAG cannot work. `ai_embedding_sources` only tracks status (`source_type`, `source_id`, `embedding_status`) — no embedding, no chunk text, no model/dimension. Need a table storing `embedding vector(N)`, chunk text, polymorphic source ref, model_id/dimension, plus an HNSW/IVFFlat index. **Largest AI-compat gap.** |
| M4 | `pc_unit_positions` (layout-scoped) | 🟠 | Position currently lives on `pc_units.pos_x/pos_y`, which breaks when a PC moves between rooms or when a room has multiple/versioned layouts. Scope position to `(laboratory_layout_id, pc_unit_id)` so "save layout positions" and real-time updates work per layout. |
| M5 | `password_reset_tokens`, `sessions` | 🟠 | Required by Laravel/Sanctum SPA cookie auth. The default Laravel `0001_01_01_000000_create_users_table.php` migration already ships these — but the DBML's `users` definition diverges from that migration (see §5). Reconcile so they aren't lost. |
| M6 | `tags` + `ticket_tags` | 🟡 | Single `category_id` limits classification. Tags improve search, AI clustering, and reporting. Optional but cheap. |
| M7 | `sla_policies` / ticket SLA fields | 🟡 | Production ticketing + reporting wants response/resolution targets. Pairs with the missing ticket lifecycle timestamps (§5). |
| M8 | `report_definitions` / `scheduled_reports` | 🟡 | "Analytics" and "Reports" are named modules. A place to persist saved/scheduled report configs. Optional now; note for the reporting phase. |
| M9 | `notification_preferences` | 🟡 | Per-user channel/opt-out settings; pairs with `notifications`. |
| M10 | `checklist_templates` | 🟡 | `maintenance_checklists` are free-text per record; templates enable consistent preventive-maintenance checklists. |
| M11 | `network_interfaces` / `device_connections` | 🟡 | The brief's *future* "Network topology visualization" will need MAC/IP and link data. Note only — do not build now. |

---

## 5. Missing columns

**`tickets`** 🟠 — `resolved_at`, `closed_at`, `first_response_at` (no SLA/MTTR is computable without
these — directly blocks the reporting requirement); `due_date`; `reporter_id` (rename of `teacher_id`,
see §7); external `uuid`. Make `category_id`, `priority_id`, `current_status_id` **`NOT NULL`** (a
ticket always has a status).

**`users`** 🟠 — `email_verified_at`, `remember_token` (Laravel auth), `deleted_at` (never hard-delete a
user with ticket history), `last_login_ip`; make `password` **`NOT NULL`**. Consider MFA columns
(`two_factor_secret`, …) and `must_change_password`.

**`procurement_requests`** 🟠 — `approved_by`, `approved_at` (there's a `request_status` but no
approval audit trail).

**`procurement_request_items`, `asset_transfers`, `qr_scan_logs`, `maintenance_checklists`,
`qr_codes`** 🟡 — no `created_at`/`updated_at`. Append-only logs can keep just `created_at`, but the
**absence is inconsistent** across the schema; standardize.

**`laboratory_layouts`** 🟠 — `grid_size` (brief requires **snap-to-grid**); `is_active`/`version` if a
room can have more than one layout.

**`pc_units`** 🟡 — `decommissioned_at` (or rely on `status`); `ip_address`/`mac_address` for the future
network-topology module; clarify `pos_x/pos_y` precision.

**`audit_logs`** 🟠 — switch `old_values`/`new_values` to `jsonb`; the `record_id` + `table_name` pair
needs an index.

**`ai_conversation_logs`** 🟡 — `tokens_used`, `latency_ms`, optional `conversation_id` grouping for
cost/observability.

**Master entities generally** 🟠 — `created_by`/`updated_by` (W4) and `deleted_at` (W2).

---

## 6. Redundant entities / columns

| Item | Grade | Recommendation |
|------|-------|----------------|
| `tickets.is_duplicate` | 🟡 | Fully derivable from `duplicate_ticket_id IS NOT NULL`. Drop, or keep only as a deliberately-maintained flag. |
| `hardware_components` vs `hardware_models` | 🟠 | Overlapping catalogs (see N1). Merge, or sharply separate responsibilities. |
| `pc_units.qr_identifier` vs `qr_codes` | 🟡 | Two QR identities (see N3). Pick a canonical one; make the other a derived/printable record. |
| `pc_specifications` vs `pc_component_installations` | 🟡 | Snapshot vs structured truth (N5). Not strictly redundant — keep the snapshot, but document authority. |
| `activity_logs` vs `audit_logs` | 🟡 | Justified split (app-action log vs row-diff log). Keep, but document the boundary so they don't drift into duplication. |
| `tickets.ai_summary/ai_confidence` vs `ai_analysis_logs` | 🟡 | Snapshot vs history (N5). Keep; document. |

No table is outright dead weight — the redundancies are about overlapping responsibilities, not unused tables.

---

## 7. Naming review

Mostly clean `snake_case`, plural tables, Laravel-conventional — good. Specific items:

- 🟠 **`tickets.teacher_id`** couples a column to a role. Technicians/admins also raise tickets. Rename
  to **`reporter_id`** (or `requested_by`). Same spirit: `tickets.technician_required` is fine, but the
  reporter FK should be role-neutral.
- 🟡 **FK columns missing `_id` suffix:** `ai_knowledge_articles.created_from_ticket` →
  `created_from_ticket_id`; `ai_system_settings.active_model` → `active_model_id`. Consistency matters
  for Laravel relation inference.
- 🟡 **Table-name-prefixed columns** (`buildings.building_name`, `laboratories.laboratory_name`,
  `maintenance_records.maintenance_title`, `roles.name` vs `*_name` elsewhere) are inconsistent. Pick a
  convention (Laravel typically `name`/`title`) and apply it uniformly. Cosmetic but worth settling
  before models are generated.
- 🟡 `qr_codes` lacks `created_at/updated_at` while its siblings have them — naming/shape inconsistency.

---

## 8. Recommended constraints

### 8.1 Unique constraints
- 🔴 `ticket_votes (ticket_id, user_id)` — **prevents double-upvoting.** Currently absent; upvote
  integrity is unenforceable without it.
- 🟠 `pc_specifications.pc_unit_id` **UNIQUE** (enforce 1:1 with `pc_units`).
- 🟠 `laboratory_layouts.laboratory_id` **UNIQUE** (if one layout per room) — or add `version`.
- 🟠 Lookup name uniqueness: `ticket_categories.category_name`, `ticket_statuses.status_name`,
  `ticket_priorities.priority_name` **and** `priority_level`, `maintenance_types.maintenance_type`,
  `suppliers.supplier_name`, `manufacturers.manufacturer_name`.
- 🟡 `ai_feedback (recommendation_id, user_id)` — one feedback per user per recommendation.
- 🟡 `ai_embedding_sources (source_type, source_id[, model_id])` — one index record per source.
- 🟡 Partial unique: at most one **active** `technician_assignments` per ticket
  (`WHERE assignment_status = 'active'`); at most one **active** installation per component slot in
  `pc_component_installations` (`WHERE removal_date IS NULL`).
- 🟡 Case-insensitive `users.email` uniqueness (`citext` or a `lower(email)` unique index).

### 8.2 Check constraints
- 🟠 Bounded scores: `tickets.ai_confidence`, `ai_analysis_logs.confidence_score`,
  `ai_predictions.probability`, `ai_failure_patterns.confidence` **`BETWEEN 0 AND 1`**.
- 🟠 Non-negative: `*.quantity > 0`, `attachments.file_size >= 0`,
  `maintenance_records.downtime_minutes >= 0` / `labor_hours >= 0`,
  `hardware_replacements.warranty_months >= 0`, counter caches `>= 0`.
- 🟠 `tickets.duplicate_ticket_id <> id`; `ticket_comments.parent_comment_id <> id` (no self-reference).
- 🟠 Date ordering: `announcements.end_date > start_date`; `maintenance_windows.end_time > start_time`;
  `pc_units.warranty_expiration >= purchase_date`; `inventory_items.warranty_expiration >= purchase_date`.
- 🟡 Geo: `qr_scan_logs.latitude BETWEEN -90 AND 90`, `longitude BETWEEN -180 AND 180`.
- 🟡 Coordinates within layout bounds: `pc_units.pos_x BETWEEN 0 AND layout.width` (app-level if
  cross-table), `pos_y BETWEEN 0 AND layout.height`.
- 🟡 Color format on `ticket_priorities.color` / `ticket_statuses.color` (`^#[0-9A-Fa-f]{6}$`).
- 🟠 Status/enum domains via `CHECK` where not backed by a lookup table (see §9).

### 8.3 Referential actions
- 🔴 Define explicit `ON DELETE` per FK as described in **W2**. No FK should be left to the default.

---

## 9. Enum candidates

Backed by **lookup tables already** (keep as-is): ticket category / priority / status, maintenance type.

The following are free-text `varchar` "state/type" columns. Recommendation: model each as a **PHP enum
cast (Laravel) + a DB `CHECK` constraint** — *not* a native PostgreSQL `ENUM` type (altering a PG enum
is migration-hostile; `varchar + CHECK` evolves cleanly and still validates at the DB):

| Column | Likely domain |
|--------|---------------|
| `users.status` | active / inactive / suspended |
| `pc_units.status` | online / offline / under_maintenance / assigned / available *(align to floor-plan icon colors)* |
| `pc_units.current_condition` | working / faulty / for_repair / decommissioned |
| `tickets`-adjacent `update_type` | comment / status_change / assignment / system |
| `technician_assignments.assignment_status` | pending / accepted / in_progress / completed / reassigned |
| `maintenance_records.maintenance_status` | scheduled / in_progress / completed / cancelled |
| `inventory_items.inventory_status` | available / installed / reserved / disposed |
| `pc_component_installations.installation_status` | installed / removed / faulty |
| `stock_transactions.transaction_type` | in / out / adjustment / transfer |
| `procurement_requests.request_status` | draft / submitted / approved / rejected / fulfilled |
| `qr_scan_logs.scan_result` | success / invalid / mismatch |
| `repair_images.image_type` | before / during / after |
| `notifications.notification_type` | info / warning / assignment / system |
| `ai_conversation_logs.sender` | user / assistant / system |
| `ai_analysis_logs.severity` | low / medium / high / critical |
| `ai_learning_events.event_type`, `ai_embedding_sources.embedding_status`, `login_history.login_status`, `audit_logs.operation`, `backup_history.backup_type`, `dashboard_widgets.widget_type`, `hardware_models.component_type` | per-domain small sets |

For values an admin must configure at runtime (anything color-coded / displayed), prefer a **lookup
table** over an enum. The current split already gets this right for tickets.

---

## 10. PostgreSQL-specific improvements

1. 🔴 **`timestamptz`** for every datetime (W1).
2. 🔴 **pgvector**: add a real `vector(N)` column + **HNSW** index (`vector_cosine_ops`) for the
   embedding store (M3). Pick the dimension to match the embedding model and store it explicitly.
3. 🟠 **`jsonb`** for `audit_logs` diffs, widget config, settings, model specs (W6) — with **GIN**
   indexes where queried.
4. 🟠 **`inet`** for IP columns; consider `macaddr` for the future network module.
5. 🟠 **`numeric(p,s)`** for all money/score/coordinate columns — never `float`/unparameterized
   `decimal` for money.
6. 🟠 **Partial indexes** for hot filtered queries: unread notifications
   (`WHERE is_read = false`), active assignments, `is_active`/`is_enabled` flags, open tickets.
7. 🟡 **BRIN indexes** on `created_at` for large append-only logs (`activity_logs`, `audit_logs`,
   `ai_conversation_logs`, `qr_scan_logs`, `login_history`) — tiny and ideal for time-range scans.
8. 🟡 **Full-text search**: `tsvector` (generated column) + **GIN** on `tickets(title, description)` and
   `ai_knowledge_articles(problem_signature, root_cause, verified_solution)`.
9. 🟡 **Declarative partitioning** (by month on `created_at`) for the highest-volume log tables — design
   the keys now even if you partition later.
10. 🟡 **`citext`** for case-insensitive `users.email` (extension already easy alongside `vector`).
11. 🟡 **Append-only protection**: revoke `UPDATE`/`DELETE` (or add a trigger) on `audit_logs` so the
    audit trail is tamper-resistant.
12. 🟡 **Identity columns** (`GENERATED ALWAYS AS IDENTITY`) over legacy serial — Laravel's
    `bigIncrements` is acceptable; note the preference.

---

## 11. Recommended indexes

**🔴 FK indexes (the big one — none currently exist).** Index *every* FK column. Non-exhaustive,
highest-traffic first:

- `users(role_id)`
- `laboratories(building_id)`; `laboratory_layouts(laboratory_id)`; `pc_units(laboratory_id)`
- `pc_specifications(pc_unit_id)`; `qr_codes(pc_unit_id)`
- `tickets(reporter_id)`, `(laboratory_id)`, `(pc_unit_id)`, `(category_id)`, `(priority_id)`,
  `(current_status_id)`, `(duplicate_ticket_id)`
- `ticket_updates(ticket_id)`, `(updated_by)`; `ticket_status_history(ticket_id)`,
  `(old_status_id)`, `(new_status_id)`, `(changed_by)`
- `ticket_votes(ticket_id)`, `(user_id)`; `ticket_comments(ticket_id)`, `(user_id)`,
  `(parent_comment_id)`; `attachments(ticket_id)`, `(uploaded_by)`
- `technician_assignments(ticket_id)`, `(technician_id)`, `(assigned_by)`
- `maintenance_records(ticket_id)`, `(pc_unit_id)`, `(technician_id)`, `(maintenance_type_id)`
- `maintenance_checklists(maintenance_record_id)`; `repair_images(maintenance_record_id)`,
  `(uploaded_by)`; `qr_scan_logs(maintenance_record_id)`, `(technician_id)`, `(pc_unit_id)`
- `hardware_replacements(maintenance_record_id)`, `(pc_unit_id)`, `(old_component_id)`,
  `(new_component_id)`; `maintenance_notes(maintenance_record_id)`, `(technician_id)`
- `hardware_models(manufacturer_id)`; `inventory_items(supplier_id)`, `(hardware_model_id)`
- `stock_transactions(inventory_item_id)`, `(performed_by)`;
  `pc_component_installations(pc_unit_id)`, `(inventory_item_id)`, `(installed_by)`
- `procurement_requests(requested_by)`; `procurement_request_items(procurement_request_id)`,
  `(hardware_model_id)`; `asset_transfers(inventory_item_id)`, `(transferred_by)`;
  `disposal_records(inventory_item_id)`, `(approved_by)`
- `ai_analysis_logs(ticket_id)`, `(model_id)`; `ai_recommendations(analysis_id)`;
  `ai_conversation_logs(ticket_id)`, `(user_id)`, `(model_id)`;
  `ai_learning_events(maintenance_record_id)`, `(ticket_id)`, `(pc_unit_id)`;
  `ai_failure_patterns(pc_unit_id)`; `ai_knowledge_articles(created_from_ticket_id)`;
  `ai_predictions(pc_unit_id)`; `ai_feedback(recommendation_id)`, `(user_id)`
- `notifications(user_id)`; `announcements(created_by)`; `activity_logs(user_id)`;
  `audit_logs(user_id)`; `login_history(user_id)`; `system_settings(updated_by)`;
  `maintenance_windows(created_by)`; `backup_history(created_by)`;
  `ai_system_settings(active_model_id)`, `(updated_by)`

**🟠 Composite / covering indexes for known access patterns:**
- `tickets(current_status_id, created_at)` — dashboard "open tickets, newest first".
- `tickets(reporter_id, created_at)` — "my tickets".
- `tickets(pc_unit_id, created_at)` — PC ticket history (floor-plan info panel).
- `notifications(user_id, is_read)` — unread badge.
- `audit_logs(table_name, record_id)` — "history for this row".
- `activity_logs(user_id, created_at)`; `login_history(user_id, login_at)`.
- `ai_failure_patterns(pc_unit_id, last_detected)`; `ai_predictions(pc_unit_id, generated_at)`.

**🟡 Specialized:** GIN (full-text) §10.8; HNSW (vector) §10.2; BRIN (time-range logs) §10.7; partial
indexes §10.6.

---

## 12. Requirements coverage (schema vs brief)

| Brief requirement | Covered? | Notes |
|-------------------|----------|-------|
| IT Service Tickets | ✅ | Strong: tickets + updates + status history + votes + comments + attachments. Add lifecycle timestamps (§5) + `reporter_id` rename (§7). |
| Computer Inventory | ✅ | `pc_units` + `pc_specifications` + `pc_component_installations`. Resolve serialized-vs-quantity (N2). |
| Asset Management | ✅ | Full lifecycle (procure→stock→install→transfer→dispose). Catalog overlap to resolve (N1). |
| Preventive Maintenance | ✅ | `maintenance_records` (nullable `ticket_id` supports scheduled PM) + checklists + windows. Consider `checklist_templates` (M10). |
| AI Troubleshooting | ⚠️ | Tables exist, but **no vector store** (M3) — RAG can't function as designed. |
| AI Knowledge Base | ⚠️ | `ai_knowledge_articles` present but needs embeddings + FTS to be retrievable (M3, §10.8). |
| Technician Assignment | ✅ | `technician_assignments` with full timestamp lifecycle. Add "one active per ticket" partial unique (§8.1). |
| Repair History | ✅ | `maintenance_records` + `repair_images` + `hardware_replacements` + `maintenance_notes`. |
| QR Code Verification | ✅ | `qr_codes` + `qr_scan_logs` (geo-stamped). Tighten QR identity model (N3). |
| Analytics | ⚠️ | Possible, but blocked on ticket lifecycle timestamps + `timestamptz`; consider materialized views. |
| Reports | ⚠️ | Same dependency; consider `report_definitions`/`scheduled_reports` (M8). |
| 3 roles (Teacher/Tech/Admin) | ✅ | `roles` + `users.role_id` + RBAC (`role_permissions`, `user_permissions`). |
| **Future: Interactive Floor Plan** | ⚠️ | Buildings/labs/layouts/positions exist, but **no `floors`**, **no offices/rooms generalization**, positions not layout-scoped, no `grid_size` (M1, M2, M4, §5). Fixable now without refactor — which is precisely the brief's mandate. |
| **Future: AI + Gemini RAG** | ⚠️ | pgvector enabled but unused; needs the embedding store (M3). |
| **Future: Network topology / heat maps** | ⚠️ | Note only — no MAC/IP/connection modeling yet (M11). Not required now. |
| Auditability | ✅ | Excellent coverage; add blame columns (W4) + jsonb audit diffs (W6). |
| Security | ⚠️ | Add external UUIDs (W5), `password NOT NULL`, PII/retention policy, append-only audit (§10.11). |
| Scalability | ⚠️ | Good PKs/counters; needs FK indexes (W3), partitioning/BRIN plan for logs (§10.7/10.9). |

**Net:** every *current-phase* requirement is structurally covered. The ⚠️ items are (a) the AI vector
store, (b) floor-plan `floors`/`offices`/layout-scoped positions, and (c) reporting timestamps — all of
which the brief flags as future modules that "must be supportable without major refactoring." Adding
them now (or at least reserving them) honors that mandate.

---

## 13. Final recommendations (prioritized)

**Do before generating any migration (cheap now, expensive later):**
1. 🔴 Convert all datetime columns to **`timestamptz`** (W1).
2. 🔴 Decide & encode **FK referential actions** + add **`deleted_at`** soft deletes on master entities (W2).
3. 🔴 Add **indexes on every FK column** (W3, §11).
4. 🔴 Add the **`ticket_votes (ticket_id, user_id)` unique** and the other 1:1 uniques (§8.1).
5. 🔴 Design the **pgvector embedding store** (M3) so RAG isn't a later re-architecture.
6. 🔴 Reconcile the DBML `users` table with Laravel's default `users` migration, incl.
   `password_reset_tokens` / `sessions` (M5, §5).

**Resolve these modeling decisions before coding (they change table shapes):**
7. 🟠 `hardware_components` vs `hardware_models` vs `inventory_items` overlap (N1).
8. 🟠 Serialized vs quantity-based inventory (N2).
9. 🟠 Floor-plan readiness: `floors` (M1), `offices`/`rooms` generalization (M2), layout-scoped
   `pc_unit_positions` (M4), `grid_size` (§5).
10. 🟠 Ticket lifecycle timestamps `resolved_at`/`closed_at`/`first_response_at` + `reporter_id` rename
    (§5, §7).

**Apply broadly during migration authoring:**
11. 🟠 `jsonb`/`inet`/`numeric(p,s)` typing (W6); `created_by`/`updated_by` blame columns (W4);
    external `uuid`/`ulid` on user-facing entities (W5).
12. 🟠 Enum domains as PHP enum + `CHECK` (§9); check constraints for bounds/dates/geo (§8.2).

**Schedule (post-MVP, but design keys now):**
13. 🟡 Full-text (GIN) + BRIN + partial indexes (§10); partitioning plan for log tables.
14. 🟡 Optional tables: tags, SLA, report definitions, notification preferences, checklist templates.
15. 🟡 Naming consistency pass (§7); append-only audit hardening (§10.11).

---

## 14. Suggested next step (for your approval)

When you're ready, I'd propose we proceed in this order — **no code until you approve a specific item**:

- **A.** You confirm the **modeling decisions** in §13 items 5–10 (these are genuine product choices —
  e.g. serialized vs quantity inventory, whether to merge the hardware catalogs, how far to take
  floor-plan readiness now). I can present these as focused either/or questions.
- **B.** I produce a **revised `database_design.dbml` (v2)** reflecting the approved decisions —
  still no migrations.
- **C.** Only after you approve v2 do we generate Laravel migrations, in dependency order, phase by
  phase.

No changes will be made to the schema or any migration until you approve. Awaiting your direction.

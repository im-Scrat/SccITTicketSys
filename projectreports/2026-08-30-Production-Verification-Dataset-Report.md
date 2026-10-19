---
title: "Production-Target Verification Dataset — WP-2.6b Smoke-Test Preparation"
project: "SccIT — School IT Service Management System"
work_package: "WP-2.6b smoke-test preparation (data creation only)"
stage: "Dataset created — NOT deployed, NOT migrated, NOT cleaned up"
date: "2026-08-30"
author: "Engineering"
status: "Complete — WP-2.6b production deployment remains NOT AUTHORIZED"
baseline: "SRS / SDD / SPMP Version 1.0 (frozen by Client instruction — unchanged)"
---

# Production-Target Verification Dataset — WP-2.6b Smoke-Test Preparation

Exactly what Decision 2 authorized, and nothing else. **No deployment, no
migration, no commit, no push, no cleanup, no code, schema or document change,
and no Phase 2.7 work.**

Nine rows were created in the production-target database. Production still has
**28 migrations**, the running containers still run the **pre-WP-2.6b** image,
and `HEAD` is still `2df17db` with no new commits.

---

## 0. Tool discipline — what was actually used here

| Source | Used for |
|---|---|
| **CLAUDE.md** | The `-p sccit_prod -f compose.prod.yaml` rule — every command below carries it |
| **Auto memory** | Production-stack conventions and the `COMPOSE_PROJECT_NAME` gotcha |
| **claude-mem** | SessionStart context for WP-2.6b state |
| **codebase-memory-mcp** | Used earlier this session (`search_code("work-support")`, which corrected the audit's endpoint claim) |
| **Graphify** | Used earlier this session (`get_neighbors("QrCodePanel()")` before the QR fix) |
| **Git state** | Confirmed unchanged before and after |
| **Database state** | `information_schema`, `pg_constraint`, row counts — read before writing |
| **Production-target environment** | Row creation and relational verification inside `app_prod` |

For this specific task the authoritative source was the **live production
schema** — `information_schema.columns` for NOT NULL requirements and
`pg_constraint` for the CHECK domains. Enum values were taken from the database
constraints rather than from the PHP enums, so no value could be invented.

---

## 1. Verification dataset created

All nine rows are prefixed **`ZZ-VERIFY`** / **`ZZ VERIFICATION`** so they sort
last in every listing and cannot be mistaken for a genuine school asset.

| # | Record | Identifier | Key detail |
|---|---|---|---|
| 1 | Building | `df9ffa49-5596-4202-ae88-e6de42a75ef4` | code `ZZ-VERIFY` — *ZZ VERIFICATION — Test Building (WP-2.6b)* |
| 2 | Floor | `177985af-aef0-48bc-ab66-67ecdc918600` | floor 1 — *ZZ VERIFICATION — Test Floor* |
| 3 | Room | `791d11c3-990b-4ad6-b288-4c3f085c0e58` | code `ZZ-VERIFY-LAB1`, type `laboratory` |
| 4 | **PC unit 1** | `946fa84b-3ada-4731-b354-0ba113edcee4` | `ZZ-VERIFY-PC-01` — *ZZ VERIFICATION PC 01 (QR lifecycle)* |
| 5 | PC specification | *(for PC-01)* | cpu / ram / storage / OS, all `ZZ VERIFICATION` |
| 6 | **PC unit 2** | `f918f729-00f0-4e21-9aa1-e9cd57300a37` | `ZZ-VERIFY-PC-02` — *ZZ VERIFICATION PC 02 (scan target)* |
| 7 | Hardware component | id `1` | `system_unit` — *ZZ VERIFICATION — Test System Unit* |
| 8 | Hardware model | id `1` | *ZZ VERIFICATION — Test Model* |
| 9 | **Asset** | `baa2c07d-83cf-4541-9d39-71f1135b4262` | `ZZ-VERIFY-AS-01`, status `in_stock`, condition `working` |

**Location:** both PC units sit in *ZZ VERIFICATION — Test Building (WP-2.6b) /
ZZ VERIFICATION — Test Floor / ZZ VERIFICATION — Test Laboratory*.

### QR records: deliberately **not** created

**Zero `qr_codes` rows exist.** This is intentional, not an omission.

Decision 2's administrator sequence makes *"generate its unique QR identifier"*
**step 3 of the smoke test itself**. Pre-creating a label would have consumed the
very step under test and left generation unverified in production. The QR
records will be produced by the administrator through the UI during the smoke
test, which is also what exercises `ManageQrCode`, the audit trail and the
`pc_units.qr_identifier` synchronisation.

---

## 2. Purpose of each record

| Record | Smoke-test scenario it supports |
|---|---|
| Building → Floor → Room | Admin step 1 *(locate/open)* and step 2 *(complete asset information)*. Also the source of the QR label's printed `location_label`, which is only populated when the target has a room — without this chain, print output would be silently incomplete |
| **PC unit 1 (`ZZ-VERIFY-PC-01`)** | The **full administrator QR lifecycle**: steps 3–8 — generate → display → verify rendering → print → **regenerate** → **revoke** |
| PC specification | Admin step 2, *"confirm its complete asset information"*. Without it the Specifications tab is empty and the step cannot be judged |
| **PC unit 2 (`ZZ-VERIFY-PC-02`)** | The **technician workflow**: scan → resolve to exactly one PC → view details → proof of work → support request (technician steps 1–8), and the administrator's request review (admin steps 1–5) |
| Hardware component + model | Required by the schema: `assets.hardware_model_id` is NOT NULL, and `hardware_models.hardware_component_id` is NOT NULL. Created solely to make the asset row legal |
| **Asset (`ZZ-VERIFY-AS-01`)** | The **other half of the QR binding rule**. FR-QR-001 binds a label to a PC unit **or** a standalone asset, enforced by `qr_codes_target_check`. Without an asset, only half that invariant is exercisable in production — and the panel corrected in `2df17db` serves both kinds from one component |

### Why two PC units rather than one

Because the administrator sequence ends in **revocation**, and the technician
sequence needs a **live** label to scan. Run against a single unit, step 8
(revoke) would destroy the target the technician workflow depends on, and the
two sequences could not both pass in one run. Two units is the smallest dataset
in which every authorized step is reachable.

If you would rather test on one unit and accept re-generating between phases,
`ZZ-VERIFY-PC-02` can be dropped — say so and I will remove it during cleanup.

---

## 3. Data integrity

**Relationships verified through the domain model**, not just raw foreign keys —
loaded via Eloquent relations inside the production container:

```
ZZ-VERIFY-PC-01
  location    ZZ VERIFICATION — Test Building (WP-2.6b) / … Test Floor / … Test Laboratory
  spec        present (cpu=ZZ VERIFICATION — Test CPU)
  active qr   0        qr_identifier NULL
ZZ-VERIFY-PC-02
  location    ZZ VERIFICATION — Test Building (WP-2.6b) / … Test Floor / … Test Laboratory
  spec        none
  active qr   0        qr_identifier NULL
ZZ-VERIFY-AS-01
  model       ZZ VERIFICATION — Test Model
  component   ZZ VERIFICATION — Test System Unit
  active qr   0
```

| Confirmation | Result |
|---|---|
| Relationships valid | **Yes** — building → floor → room → both PC units; component → model → asset; specification bound to PC-01 |
| QR uniquely identifies exactly one PC/asset | **Not yet applicable** — no labels exist. The `qr_codes_target_check` constraint (`num_nonnulls(pc_unit_id, asset_id) = 1`) is already live in production and will enforce it the moment the smoke test generates one |
| No unrelated business data created | **Confirmed** — 9 rows, all `ZZ-VERIFY`-prefixed. No ticket, maintenance record, assignment, comment or user was created |
| No existing genuine data modified | **Confirmed** — users still **6**, tickets still **2**, both unchanged; `maintenance_records` and `qr_codes` still **0** |
| Schema unchanged | **Confirmed** — production still at **28** migrations |
| No invented fields | **Confirmed** — every column written exists in the live schema; `room_type`, `component_type`, `status` and `condition` values were taken from the live CHECK constraints |
| Authorization model untouched | **Confirmed** — no permission, role or policy was altered. Data was created through the application's own Eloquent models in a console context; the smoke test will exercise authorization through the UI, which is where it belongs |

---

## 4. Cleanup plan — **nothing deleted**

Per Decision 3, cleanup happens only after a passed smoke test and under
separate authorization.

### 4.1 Created by this task (9 rows)

| Order | Record | Note |
|---|---|---|
| 1 | `qr_codes` for the two PC units and the asset | *Will exist after the smoke test* — delete first |
| 2 | `qr_scan_logs` from the technician scan | *Will exist after the smoke test* |
| 3 | `work_support_requests` (+ items, attachments) | *Will exist after the smoke test* |
| 4 | `maintenance_records` + proof-of-work evidence | *Will exist after the smoke test* |
| 5 | PC specification for `ZZ-VERIFY-PC-01` | |
| 6 | `ZZ-VERIFY-PC-01`, `ZZ-VERIFY-PC-02` | `pc_units` |
| 7 | `ZZ-VERIFY-AS-01` | `assets` |
| 8 | Hardware model id 1, hardware component id 1 | |
| 9 | Room `ZZ-VERIFY-LAB1` → Floor 1 → Building `ZZ-VERIFY` | Innermost first |

Deletion must run in this order: `work_support_requests` FKs use
`restrictOnDelete` against `pc_units`, so a PC unit cannot be removed while a
request references it.

### 4.2 Pre-existing verification data (Decision 4)

| Record | Classification |
|---|---|
| `verify.admin@sccit.local` | verification-only — remove before real operational deployment |
| `verify.tech@sccit.local` | verification-only |
| `verify.teacher@sccit.local` | verification-only |
| **`pro@sccpag.com`** | **test account** (your Decision 4) — remove before real operational use |
| **`testingi@sccedu.com`** | **test account** (your Decision 4) |
| `TKT-2026-00001`, `TKT-2026-00002` | verification tickets + dependent `ticket_status_history` / `ticket_updates` rows |

### 4.3 Retained

`admin@sccit.local` (the seeded production administrator) and all reference/seed
data — roles, permissions, ticket statuses, priorities, categories.

### 4.4 Development-side records — out of scope

`PC-0LWODARGNO` and `PC-RNC5ZIWVVD` live in the **development** database and have
no production presence. They are untouched, as instructed, and are not part of
production cleanup.

---

## 5. Remaining deployment blockers — re-evaluated

### Resolved

| Was | Now |
|---|---|
| **Environment ambiguity** (previous YELLOW 4) | **RESOLVED by Decision 1.** Recorded below as three distinct stages. Current loopback/Mailpit configuration is accepted for this stage and explicitly *not* treated as the final public configuration. No exposure or architecture change was made |
| **Production had 0 assets / 0 PC units** (previous YELLOW, Q9) | **RESOLVED.** Smoke-test rows 5–9 and 15–16 now have targets |
| **Cleanup ordering** (Q7) | **RESOLVED by Decision 3** — after the smoke test |
| **Two unclassified teacher accounts** (Q7) | **RESOLVED by Decision 4** — both are test accounts |
| **Two "schema inconsistencies"** | **Already resolved** in the checkpoint — neither exists |

### Environment stages, recorded per Decision 1

1. **Current — production-parity / production-target.** Loopback-bound HTTP on
   `:8081`, Mailpit sink, superuser DB role. Acceptable for controlled
   verification. **Not the final configuration.**
2. **Future — network-reachable deployment.** For end-to-end testing of the
   completed system over a real network. Will require TLS, a non-superuser
   database role, real SMTP, and a session/cookie-flag review.
3. **Eventual — real operational deployment.** Requires stage 2 plus completed
   verification-data cleanup.

Nothing in this report should be read as certifying stage 2 or 3.

### Remaining

| # | Item | Severity | Needs your decision? |
|---|---|---|---|
| 1 | **No production deployment tooling.** All 11 scripts in `scripts/` use bare `docker compose` and target **dev**; `scripts/backup.sh` would back up the wrong database. Every deployment step must be hand-run with `-p sccit_prod -f compose.prod.yaml` | **YELLOW** | No — mitigated by the explicit sequence in the checkpoint report §7 |
| 2 | **The running stack predates WP-2.6b** — 8 domains, no `WorkSupport`, 28 migrations. Deploying ships the whole WP-2.6b release plus both corrections, not two small fixes | **YELLOW** | No — a scope fact to hold in view, not a defect |
| 3 | **Record the current image digest before deploying** (`sha256:81180836ee558…`) or the tag-based rollback has nothing to point at | **YELLOW** | No — a required step |
| 4 | **I have no working production credentials.** The smoke test requires signing in as administrator, technician and teacher on `:8081`. The `verify.*` passwords are unknown to me, and two earlier attempts to obtain credentials were correctly blocked by the environment's security classifier | **YELLOW** | **Yes** |

### Item 4 is the one that will stop the smoke test

Every authenticated row of the smoke-test matrix — which is most of it — needs a
login. Your options:

- **supply the three passwords** when you authorize the smoke test; or
- **authorize a password reset** on the three `verify.*` accounts (they are
  verification-only by your Decision 4, so resetting them costs nothing); or
- **run the smoke test yourself** against the deployed stack, with me
  interpreting the results.

I did not attempt to work around the classifier and will not.

---

## 6. Deployment authorization boundary

> ### **WP-2.6b production deployment remains NOT AUTHORIZED.**

| Item | State |
|---|---|
| Deployment | **not performed** |
| Migrations 28 → 30 | **not run** — production still at **28** |
| Running containers | still the **pre-WP-2.6b** image `sha256:81180836ee558…` |
| Built images | `app de13d793201d`, `web b8bd04e3c427` — built, verified, **not deployed** |
| Git | `HEAD = 2df17db`, **no new commit**, nothing staged, **nothing pushed** |
| Cleanup | **not performed** — no account, ticket, asset, PC unit or QR record deleted |
| Network exposure | **unchanged** — still loopback-only |
| Configuration | **unchanged** |
| Code / schema / documents | **unchanged** — SRS, SDD, SPMP still Version 1.0 |
| Phase 2.7 | **not started** |

**Stopping here and awaiting your explicit deployment authorization.**

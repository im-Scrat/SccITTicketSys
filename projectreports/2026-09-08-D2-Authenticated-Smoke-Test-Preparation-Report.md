---
title: "D2 AUTHENTICATED SMOKE TEST PREPARATION REPORT"
project: "SccIT — School IT Service Management System"
work_package: "Phase 2.7 — production deployment readiness (D2 preparation)"
stage: "PREPARED — NOT YET EXECUTED"
date: "2026-09-08"
author: "Engineering"
status: "No deployment · no migration · no data created · no production write · nothing pushed"
baseline: "HEAD 6cd219e · production at migration 30"
---

# D2 AUTHENTICATED SMOKE TEST PREPARATION REPORT

Everything in this report is **PREPARED**. **Nothing was executed**, and **no row
was written to production.** Two findings changed the shape of the plan and are
stated first, because they alter what you asked for.

---

## Finding 1 — the two named accounts no longer exist

You described `pro@sccpag.com` and `testingi@sccedu.com` as existing test
accounts and instructed *"Do not delete the existing test accounts yet."*

**Neither account exists.** I searched the production database, the development
database and the test databases: **0 matches** in all three. Production holds
exactly **one** user — `admi***@sccit.local`, the seeded administrator, created
2026-07-06.

They did exist. The WP-2.6b Release and Closure Report records them as user ids
2 and 3, and the WP-2.6b verification dataset was removed on 2026-08-30 — the
`backups/prod/…preCLEANUP-20260830-213102.sql.gz` snapshot marks that cleanup.
Both accounts went with it.

**There is nothing to preserve and nothing to delete.** All verification accounts
must be created fresh.

## Finding 2 — both named accounts were **teachers**, so the gap is a Technician

You asked me to *"identify that requirement before creating anything"* for a
third Teacher account. The requirement runs the other way:

| Account | Role it held | Created |
|---|---|---|
| `testingi@sccedu.com` | **teacher** | 2026-07-06 |
| `pro@sccpag.com` | **teacher** | 2026-07-07 |

Two teachers, no technician. Since the smoke matrix requires a Technician login,
My Submissions, an assigned asset, the QR workflow and technician notification
preferences, **a Technician account is the one that must exist and never did**.

A third *Teacher* account is **not required** — one teacher is sufficient for
ticket creation and the authorization-boundary checks.

---

## 1. Test accounts

**PREPARED — not created.**

| # | Account | Role | Purpose | Status |
|---|---|---|---|---|
| A1 | `verify.admin@sccit.local` | administrator | Admin surfaces, asset/QR management, Support Requests, announcements | **To create** |
| A2 | `verify.tech@sccit.local` | technician | My Submissions, assigned asset, QR workflow, proof of work | **To create** |
| A3 | `verify.teacher@sccit.local` | teacher | Ticket creation, own-ticket access, authorization boundary | **To create** |

**Why not reuse the two names you gave.** They were teachers, they no longer
exist, and their domains (`sccpag.com`, `sccedu.com`) look like real
organisations rather than verification artifacts. The `verify.*@sccit.local`
convention is the one this project already used for exactly this purpose in
WP-2.6b, and it makes every verification account identifiable by prefix at
cleanup time. **If you prefer the original two names, say so and I will use
them** — but at least one must be a technician.

**Why the existing `admin@sccit.local` is not used as A1.** It is the real
production administrator. Using it would attribute smoke-test asset creation,
QR generation and announcement publishing to the genuine operator account in
the permanent audit trail. A separate `verify.admin` keeps the audit history
honest and is removable.

**Credentials are yours to set, by design.** `POST /api/admin/users` requires the
creating administrator to supply the password, with an optional
`force_password_reset`. I neither generate nor hold these. No password appears
in this report.

---

## 2. Required roles

All three seeded roles are present in production (`roles=3`, `permissions=50`),
so no role or permission work is needed:

| Role | Permissions | Needed for |
|---|---|---|
| administrator | 50 | Asset/PC management, QR generation, Support Requests, announcements, user management |
| technician | 16 | My Submissions, assigned work, QR scan → panel → proof of work |
| teacher | 7 | Ticket creation and own-ticket access only |

---

## 3. Required minimum test data

**PREPARED as a specification — none created.**

Production reference data is ready: 7 ticket statuses, 4 priorities, 6
categories, 5 maintenance types. Missing entirely: buildings (0), rooms (0),
hardware models (0), PC units (0), assets (0), QR codes (0).

| # | Record | Value | Why it is the minimum |
|---|---|---|---|
| D1 | Building | `ZZ-VERIFY` | A room cannot exist without a floor, nor a floor without a building |
| D2 | Floor | Floor 1 of `ZZ-VERIFY` | Same chain |
| D3 | Room / laboratory | `ZZ-VERIFY-LAB1` | A PC unit needs a location for the panel to be meaningful |
| D4 | Hardware model | one generic model | `pc_specifications` and component records reference it |
| D5 | PC unit | `ZZ-VERIFY-PC-01`, in D3 | **The QR subject.** `unit_code` and `pc_name` are the only required fields |
| D6 | PC specification | for D5 | So "view complete asset information" has something complete to show |
| D7 | **QR code** | generated for D5 | **Bound to D5 — see §7.** Not a mock |
| D8 | Ticket | one, reported by A3 | Exercises creation, authorization and the notification triggers |
| D9 | Technician assignment | D8 → A2 | Fires the assignment notification and populates the technician queue |
| D10 | Announcement | one, audience `all` | Exercises WP-2.7c publish → notify → read |

**Deliberately excluded** as unnecessary: a second PC unit, a second building,
consumables, procurement records, extra tickets, historical maintenance, and any
fabricated business history. The `ZZ-VERIFY` prefix on every location record
makes the whole dataset identifiable in one query at cleanup.

**One ordering constraint carried forward from WP-2.6b:** `work_support_requests`
uses `restrictOnDelete` against `pc_units`, so cleanup must delete requests
before PC units. That constraint shapes §9.

---

## 4. Exact smoke-test scenarios

**PREPARED — NOT YET EXECUTED.** All run against `http://localhost:8081` after
deployment.

### Automated (already exists — 5 anonymous tests)

`sh scripts/e2e.sh --project smoke` — landing page boots with no console or CSP
errors; the inline theme script survives the production CSP; a client-side route
loads through the SPA fallback; `/up` answers; an anonymous request for a
protected resource is refused.

### Manual — Administrator (A1)

| # | Scenario |
|---|---|
| ADM-1 | Sign in |
| ADM-2 | Dashboard renders with administrator widgets |
| ADM-3 | Asset/PC management: list, open `ZZ-VERIFY-PC-01` |
| ADM-4 | Complete asset information: specification, location, status all present |
| ADM-5 | QR identification: generate/display the QR for D5 |
| ADM-6 | QR display/print surface renders the label |
| ADM-7 | Support Requests management surface loads |
| ADM-8 | Notification centre: list, filter, mark read |
| ADM-9 | Notification preferences: **27 cells** (3 channels × 9 types) |
| ADM-10 | Announcements: publish D10 |
| ADM-11 | Announcement management surface shows every audience |

### Manual — Technician (A2)

| # | Scenario |
|---|---|
| TEC-1 | Sign in |
| TEC-2 | Dashboard renders with technician widgets |
| TEC-3 | My Submissions loads and is scoped to this technician |
| TEC-4 | Assigned asset/ticket from D9 appears |
| TEC-5 | **QR scan → panel** (§7) |
| TEC-6 | Technician workflow: proof of work against the scanned unit |
| TEC-7 | Submission/support-request workflow |
| TEC-8 | Notification centre shows the assignment notification from D9 |
| TEC-9 | Notification preferences: 27 cells |

### Manual — Teacher (A3)

| # | Scenario |
|---|---|
| TCH-1 | Sign in |
| TCH-2 | Dashboard renders with teacher widgets |
| TCH-3 | Create ticket D8 |
| TCH-4 | Own ticket is visible and openable |
| TCH-5 | Notification centre shows updates on their own ticket |
| TCH-6 | Notification preferences: 27 cells |

### System

| # | Check | Command |
|---|---|---|
| SYS-1 | `/up` | `curl -sI http://localhost:8081/up` → 200 |
| SYS-2 | Database | `migrate:status` → **32 Ran** |
| SYS-3 | Redis / cache | `Cache::put`/`get` round trip |
| SYS-4 | Queue | `queue_prod` running; `failed_jobs` not growing |
| SYS-5 | Scheduler | `schedule:list` → 4 commands, digest at `0 23 * * *` |
| SYS-6 | Mail | Message appears in Mailpit `:8026` |
| SYS-7 | Storage | Attachment upload and retrieval |
| SYS-8 | Logging | `docker compose -p sccit_prod logs app_prod` shows structured stderr output |

### Regression

| # | Workflow |
|---|---|
| REG-1 | Ticket workflow: create → assign → comment → status change |
| REG-2 | Maintenance workflow: open a record against D5, complete with evidence |
| REG-3 | QR workflow end to end (§7) |
| REG-4 | Authorization boundaries (§6) |
| REG-5 | Administrator Support Requests |
| REG-6 | Technician My Submissions scoping |

---

## 5. Expected results

| Area | Expected |
|---|---|
| Sign-in, all three roles | Redirect to the role's dashboard; session cookie set; no console errors |
| Dashboards | Each role sees its own layout; no widget errors; no cross-role data |
| Asset information | `ZZ-VERIFY-PC-01` shows unit code, name, room, specification, status |
| QR | Scanning resolves to **exactly** `ZZ-VERIFY-PC-01` (§7) |
| Notification centre | Only the signed-in user's notifications; unread badge matches the list |
| Notification preferences | **27 cells**; digest column present and labelled "Digest"; absent rows render enabled |
| Announcement | Published to `all`; teacher and technician both receive an in-app notification; **no email is sent** |
| Digest | No digest at smoke time (it runs 07:00 Manila for the *previous* day); verify by invoking `notifications:send-digest --date <yesterday>` and observing Mailpit |
| Tickets | Teacher sees only their own; technician sees assigned; administrator sees all |
| System | All eight SYS checks pass |
| Failures | Any authorization failure is an immediate **ROLLBACK** per the release gate's §14 matrix |

---

## 6. Authorization boundaries

These are the checks that matter most; each must be attempted and **must fail**.

| # | Attempt | Expected |
|---|---|---|
| AUTH-1 | Teacher opens `/app/users` | Forbidden |
| AUTH-2 | Teacher opens `/app/assets` | Forbidden |
| AUTH-3 | Teacher opens `/app/announcements/manage` | Forbidden |
| AUTH-4 | Teacher requests another user's ticket by uuid | 403/404 — **not** merely hidden |
| AUTH-5 | Teacher requests another user's notification by uuid | Refused |
| AUTH-6 | Technician opens `/app/announcements/manage` | Forbidden |
| AUTH-7 | Technician opens QR label management | Refused |
| AUTH-8 | Technician sets another user's notification preferences | Ignored/refused |
| AUTH-9 | Teacher scans the QR for `ZZ-VERIFY-PC-01` | Refused **without disclosing** whether the unit exists |
| AUTH-10 | Anonymous request for any `/api/admin/*` route | Unauthorized |
| AUTH-11 | Administrator reads another user's notifications | Refused — no admin override (DD-62) |
| AUTH-12 | Teacher's announcement reader excludes a technicians-only announcement, **and** refuses it by uuid | Both |

AUTH-4, AUTH-5, AUTH-9 and AUTH-12 are the IDOR checks — a filtered list without
the matching single-record refusal is the defect they exist to catch.

---

## 7. QR verification procedure

**This is a real end-to-end check, not a UI-only one**, exactly as you required.
The chain is **asset exists → QR exists → QR identifies the exact asset →
authenticated technician continues the workflow**.

| Step | Action | Verifies |
|---|---|---|
| Q1 | Create `ZZ-VERIFY-PC-01` (D5) with its specification (D6) | **Asset exists** — a real `pc_units` row, not a fixture |
| Q2 | As A1, `POST /api/admin/pc-units/{uuid}/qr` | **QR exists** — a real `qr_codes` row bound to that unit |
| Q3 | Record the returned code and confirm `qr_identifier` on the PC unit | The binding is persisted, not just rendered |
| Q4 | As A1, open the QR display/print surface | The label renders and carries the same code |
| Q5 | **Anonymous** `GET /api/qr/{code}/scan` | Routes to sign-in **without disclosing the machine** |
| Q6 | As A2 (technician), `GET /api/qr/{code}/scan` | **Identifies exactly `ZZ-VERIFY-PC-01`** — assert the returned uuid equals D5's uuid, not merely that a panel rendered |
| Q7 | As A2, open `/api/qr/{code}/panel` | **Technician continues the workflow** — the scan-scoped panel opens |
| Q8 | As A2, submit proof of work via `/api/qr/{code}/proof` | The job workflow completes against the scanned unit |
| Q9 | As A3 (teacher), `GET /api/qr/{code}/scan` | **Refused, without revealing whether the unit exists** |
| Q10 | Scan an unknown code, anonymously and as A2 | Answers identically to a real one — no enumeration |

**Q6 is the assertion that makes this non-superficial:** the response's asset
uuid must equal the uuid created in Q1. A panel that renders is not proof that
it resolved the right machine.

---

## 8. Notification verification

Phase 2.7 is the release under test, so these are the scenarios that matter most.

| # | Check | Expected |
|---|---|---|
| NOT-1 | D9 assignment fires trigger 1 | A2 receives an in-app notification |
| NOT-2 | Teacher comments on D8 | A2 notified; teacher not notified of their own action |
| NOT-3 | Status change on D8 | Reporter notified |
| NOT-4 | Internal comment on D8 | **Teacher receives nothing** — the existence of an internal note is staff-only |
| NOT-5 | Publish D10 to `all` | A2 and A3 both notified in-app |
| NOT-6 | Mailpit after NOT-5 | **No email for the announcement** — the DD-66 exclusion |
| NOT-7 | Publisher exclusion | A1 does **not** receive their own announcement |
| NOT-8 | Edit D10 and save | **No new notification** — an edit must not re-notify |
| NOT-9 | "Notify again" on D10 | A new notification does arrive |
| NOT-10 | Preference matrix, each role | 27 cells; a disabled cell suppresses that type |
| NOT-11 | Lockout email preference | Cannot be switched off; the UI explains why |
| NOT-12 | Digest, `--date <yesterday>` | One email per opted-in user with unread items, **titles only, no message bodies** |
| NOT-13 | Digest re-run, same date | **Nothing sent** — at-most-once holds |
| NOT-14 | `notification_digests` | One row per recipient, `sent_at` populated |
| NOT-15 | User with nothing unread | **No digest email and no row** |

NOT-6, NOT-8, NOT-13 and NOT-15 are negatives — the assertions that fail loudly
if a guarantee has regressed.

---

## 9. Cleanup plan

**No cleanup now.** This executes only after deployment, smoke testing, all
workflows passing, and final verification — then as a separately approved plan.

**Deletion order is mandatory** (`work_support_requests` → `pc_units` uses
`restrictOnDelete`):

| Order | Record |
|---|---|
| 1 | `qr_scan_logs` for the verification code |
| 2 | `qr_codes` for `ZZ-VERIFY-PC-01` |
| 3 | `work_support_requests` (+ items, attachments) |
| 4 | `maintenance_records` + proof-of-work evidence |
| 5 | `notification_digests` rows for A1–A3 |
| 6 | Notifications for A1–A3 |
| 7 | Announcement D10 |
| 8 | Ticket D8 (+ status history, updates, comments) |
| 9 | `pc_specifications` for D5 |
| 10 | `pc_units` `ZZ-VERIFY-PC-01` |
| 11 | Hardware model D4 |
| 12 | Room `ZZ-VERIFY-LAB1` → Floor 1 → Building `ZZ-VERIFY` |
| 13 | Users A1, A2, A3 |

**Retain:** `admin@sccit.local`; the 8 existing `activity_logs` rows; **the
activity_logs generated by smoke testing** — deleting audit rows to tidy up is
the wrong instinct; all `backups/prod/*` snapshots; roles, permissions and
reference data.

**Requires your decision at cleanup time:** whether A1–A3 are deleted or
suspended (suspension preserves audit foreign keys); whether `notification_digests`
rows are cleared, since leaving them suppresses a real digest for that user/date;
and whether Mailpit's production mailbox is emptied.

---

## 10. Dependencies and blockers

| # | Item | Status |
|---|---|---|
| 1 | **Deployment must happen first** | Blocking by design — 8 of the 27 notification cells and the entire digest do not exist at migration 30 |
| 2 | Account creation requires an administrator session | The seeded `admin@sccit.local` provides it |
| 3 | **Passwords must be set by you** | `POST /api/admin/users` requires the creator to supply them; I hold none |
| 4 | Naming decision | `verify.*@sccit.local` versus the two original names (§1) |
| 5 | **Mail is capture-only** | Mailpit never delivers. NOT-6 and NOT-12 verify *what was captured*, not real delivery. Open decision **D4** |
| 6 | Digest timing | 07:00 Manila for the previous day; verify with `--date` rather than waiting |
| 7 | Storage check | SYS-7 needs one attachment upload; covered by REG-2's evidence |
| 8 | No automated authenticated coverage | Confirmed: `sccit:e2e-fixtures` refuses to run in production by design, and that guard should not be weakened |

**No blocker prevents preparation.** Item 1 is the intended sequence, not an
obstacle.

---

## 11. Exact actions required after deployment

In order. **None performed.**

| # | Action | Who |
|---|---|---|
| 1 | Complete the §13 deployment runbook through step 18 | Engineering |
| 2 | Confirm `migrate:status` = **32 Ran** | Engineering |
| 3 | Sign in as `admin@sccit.local` | Owner |
| 4 | Create A1, A2, A3 with passwords you choose | Owner |
| 5 | Create D1–D3 (building → floor → room) | A1 |
| 6 | Create D4–D6 (model, PC unit, specification) | A1 |
| 7 | Generate the QR (D7) and record the code | A1 |
| 8 | Run `sh scripts/e2e.sh --project smoke` (5 anonymous tests) | Engineering |
| 9 | Execute ADM-1 … ADM-11 | A1 |
| 10 | Create ticket D8 as A3; execute TCH-1 … TCH-6 | A3 |
| 11 | Assign D8 to A2 (D9); execute TEC-1 … TEC-9 | A1 then A2 |
| 12 | Execute the QR chain Q1 … Q10 | A1, A2, A3 |
| 13 | Execute NOT-1 … NOT-15 | All three |
| 14 | Execute AUTH-1 … AUTH-12 | All three |
| 15 | Execute REG-1 … REG-6 | All three |
| 16 | Execute SYS-1 … SYS-8 | Engineering |
| 17 | Record every result; apply the §14 rollback matrix | Owner |
| 18 | **Only if all pass:** produce the cleanup plan for approval | Engineering |
| 19 | Execute approved cleanup | Engineering |

---

## Status

**PREPARED:** account specification, role mapping, minimum dataset, 5 automated
plus 55 manual scenarios, expected results, 12 authorization boundaries, the
10-step QR chain, 15 notification checks, a 13-step ordered cleanup plan, and
the post-deployment action list.

**NOT YET EXECUTED:** every one of the above. No account created, no data
written, no test run, no deployment, no migration, nothing deleted, nothing
pushed. Production remains at migration 30 with one user.

**Two answers needed before execution:** the account-naming decision in §1, and
confirmation that Mailpit's capture-only behaviour is acceptable for NOT-6 and
NOT-12 (open decision D4).

Stopping here as instructed. D3, D4 and D5 remain unaddressed pending your
authorization.

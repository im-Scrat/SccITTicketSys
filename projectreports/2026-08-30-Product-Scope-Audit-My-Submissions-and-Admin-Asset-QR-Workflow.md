---
title: "Product Scope Audit — My Submissions and the Administrator Asset/QR Workflow"
project: "SccIT — School IT Service Management System"
work_package: "Audit against WP-2.3 / WP-2.4 / WP-2.6b (SPMP)"
stage: "Audit only — no code, documents or deployment changed"
date: "2026-08-30"
author: "Engineering"
status: "Complete — awaiting authorization for the single recommended fix"
baseline: "SRS / SDD / SPMP Version 1.0 (frozen by Client instruction)"
---

# Product Scope Audit — My Submissions and the Administrator Asset/QR Workflow

**Production deployment is paused and remains paused.** Nothing was deployed, no
migration was run, no commit was created, no application code was modified, no
document was edited, and Phase 2.7 was not started. The working tree still holds
only the two untracked WP-2.6b closure-report files that existed before this
audit began.

This report answers two Client-raised concerns:

1. **"My Submissions" is a technician-only feature and the administrator must not
   have it.**
2. **The administrator asset registration and QR-management workflow appears to
   be missing.**

The first is **confirmed as a real defect**. The second is **not a defect** — the
functionality exists in full, and this report shows why it looked absent.

---

## 1. Method — what was actually examined

Every claim below was verified against the repository, the live development
runtime, or a test run. Nothing was taken from memory.

| Source | How it was used |
|---|---|
| Repository source | Read directly — routes, controllers, policies, actions, services, seeders, React pages, layout navigation |
| Live dev runtime | `docker compose exec -T app php artisan route:list --path=qr` and `--path=work-support` |
| Test suite | `docker compose exec -T app ./vendor/bin/pest tests/Feature/Assets/QrCodeTest.php tests/Feature/Qr` |
| Database schema | Migration source, including the `qr_codes` CHECK constraint |
| SRS / SDD / SPMP | Read at Version 1.0 — FR-WSR-*, FR-QR-*, SDD §14.1 and revision history, SPMP WBS rows |
| CLAUDE.md | Operating contract, source-of-truth hierarchy, parity and testing rules |
| Auto-memory | Session index (project state, conventions, known flakes) |
| claude-mem | SessionStart context for WP-2.6b, including its recorded deferrals |

**Tool-usage note, stated honestly.** Graphify and codebase-memory-mcp were both
rebuilt at the WP-2.6b release commit (`3493660`), which is still `HEAD`, so both
indexes are current and no refresh was required. This audit did not need to query
them: every question asked here was about *specific named files*, and CLAUDE.md
§3 is explicit that a graph answer is a hypothesis until the file confirms it.
Reading the files directly was the shorter path to the same answer with none of
the uncertainty. **No graph query was run, and none is claimed.**

---

## 2. Finding A — "My Submissions" is visible to the administrator

### 2.1 Current state

| Item | Finding |
|---|---|
| Route | `/app/work-support` — `frontend/src/App.tsx:317-324` |
| Navigation item | `label: 'My submissions'` — `frontend/src/layouts/AppLayout.tsx:105-106` |
| Navigation gate | `permission: 'maintenance.view'` — **no `roles` key** |
| Route gate | `<RequirePermission permission="maintenance.view">` — **no role guard** |
| Page component | `frontend/src/features/work-support/pages/MySubmissionsPage.tsx` |
| Backing endpoint | `GET /api/work-support-requests` |
| Controller | `WorkSupportRequestController.php:65` — uses `scopeOwn` |

### 2.2 Can the administrator see it? Yes.

`backend/database/seeders/PermissionSeeder.php:92` grants the administrator role
**every permission in the system**:

```php
Role::query()->where('slug', 'administrator')->first()
    ?->permissions()->syncWithoutDetaching($all);
```

The administrator therefore clears the `maintenance.view` floor, the navigation
filter admits the item, and the route guard admits the page.

**The Client's correction is confirmed. This requires correction.**

### 2.3 Two qualifications, stated precisely

**It is not a data leak.** The endpoint uses `scopeOwn`, not `scope`
(`WorkSupportVisibility.php:87-100`):

```php
public function scopeOwn(Builder $query, User $user): Builder
{
    return $query->where('work_support_requests.technician_id', $user->getKey());
}
```

An administrator opening the page sees only rows whose `technician_id` is their
own — in practice, none. **No technician's submission is exposed to anyone.** The
defect is product scope and navigation, not authorization. That distinction
matters for how the fix is classified and how urgently it must ship.

**The exposure was deliberate, and its reasoning is written into the code.** Two
comments argue for it — `AppLayout.tsx:96-104`:

> *"The page is scoped to the reader's own submissions server-side, so an
> administrator opening it sees their own, not the estate."*

and `WorkSupportVisibility::scopeOwn`'s docblock:

> *"A page whose meaning changes with the reader's role is a page that lies to
> exactly one of them."*

That reasoning is internally coherent, but it conflicts with the Client's
directive, which is now the governing decision. The correction is therefore a
navigation and route-guard change **plus removal of the justification comments** —
leaving them in place would leave the codebase arguing against its own behaviour.

---

## 3. Finding B — Administrator Support Requests is correct and untouched

| Item | Finding |
|---|---|
| Route | `/app/work-support/manage` — `frontend/src/App.tsx:325-331` |
| Guard | `<RequireRole roles={['administrator']}>` — role, not permission |
| Navigation item | `label: 'Support requests'`, `roles: ['administrator']` — `AppLayout.tsx:115-116` |
| Backend gate | `WorkSupportVisibility::canSeeAdministrative()` — role-gated |

The role guard is used rather than a permission because `maintenance.view` cannot
close this surface: every technician holds it. This is the same shape already used
by Ticket management and Maintenance management, and it follows Client decision
OD-4 — **no `wsr.*` permission was invented**, so the §8.4 permission matrix is
unchanged.

### 3.1 The three approved decisions are all present

Verified in the live development route table:

| Decision | Endpoint | Resulting state |
|---|---|---|
| **A — Approve + reschedule** | `POST /api/admin/work-support-requests/{uuid}/approve` | `approved` |
| **B — Request face-to-face** | `POST /api/admin/work-support-requests/{uuid}/request-clarification` | `clarification_requested`, labelled **"Face-to-face requested"** |
| **C — Decline + mandatory explanation** | `POST /api/admin/work-support-requests/{uuid}/decline` | `declined` |

Plus administrator `close`, technician `cancel`, and technician `acknowledge` of a
reschedule. Six controlled states in `backend/app/Enums/WorkSupportStatus.php`,
with no client-settable status (SDD DD-54). SRS FR-WSR-008 requires the decline
reason be enforced server-side; the SDD records it as **database-enforced**.

**Confirmed: this remains the administrator inbox. No change is recommended, and
none was made.**

---

## 4. Finding C — Administrator asset management: **FULLY IMPLEMENTED (Classification A)**

This is the material finding of the audit. **The workflow the Client could not
find already exists, end to end, in both backend and frontend, and is
administrator-only.** It is not missing, not partial, and not deferred.

### 4.1 Capability-by-capability evidence

| Capability | Backend | Frontend | Status |
|---|---|---|---|
| Asset creation | `CreateAsset`, `CreatePcUnit` | `AssetFormDrawer`, `PcUnitFormDrawer`; "Add asset" / "Add PC unit" at `AssetsPage.tsx:178-181` | Implemented |
| Asset editing | `UpdateAsset`, `UpdatePcUnit` | Same drawers in edit mode | Implemented |
| Asset detail view | `AssetDetailResource`, `PcUnitDetailResource` | `AssetDetailPage.tsx`, `PcUnitDetailPage.tsx` | Implemented |
| PC specifications | `UpsertPcSpecification`, `PcSpecificationResource` | `SpecificationEditor` at `PcUnitDetailPage.tsx:178` | Implemented |
| QR generation | `POST …/qr` → `ManageQrCode::generate` | "Generate QR code" in `QrCodePanel` | Implemented |
| QR printing | `GET …/qr/print` → SVG via `bacon/bacon-qr-code` | "Print label" opens a dedicated print window | Implemented |
| QR regeneration | `POST …/qr/regenerate` | Confirm dialog | Implemented |
| QR revocation | `POST …/qr/revoke` | Confirm dialog | Implemented |
| Unique QR-to-PC binding | `qr_codes_target_check` CHECK constraint | Panel is per-asset / per-PC | Implemented |
| Administrator authorization | `manageQr` → `assets.update` (`PcUnitPolicy.php:127`, `AssetPolicy.php:145`) | Nav `permission: 'assets.view'` | Implemented |

### 4.2 Why it is administrator-only

`PermissionSeeder.php:68-77` grants technicians the whole `maintenance` module
plus a named list (`tickets.*`, `inventory.*`, `ai.*`, `knowledge.*`,
`reports.view`, `floorplan.view`). **No `assets.*` permission is granted to
technicians at all** — the deliberate position recorded as SDD DD-38. Teachers
receive a still narrower set. The "Assets" navigation item is therefore invisible
to both by construction, not by hiding.

### 4.3 Runtime evidence

`php artisan route:list --path=qr` in the development container returned **15
routes** — all 10 administrator QR routes registered and live:

```
GET|HEAD  api/admin/assets/{asset:uuid}/qr
POST      api/admin/assets/{asset:uuid}/qr
GET|HEAD  api/admin/assets/{asset:uuid}/qr/print
POST      api/admin/assets/{asset:uuid}/qr/regenerate
POST      api/admin/assets/{asset:uuid}/qr/revoke
GET|HEAD  api/admin/pc-units/{pc_unit:uuid}/qr
POST      api/admin/pc-units/{pc_unit:uuid}/qr
GET|HEAD  api/admin/pc-units/{pc_unit:uuid}/qr/print
POST      api/admin/pc-units/{pc_unit:uuid}/qr/regenerate
POST      api/admin/pc-units/{pc_unit:uuid}/qr/revoke
```

plus the five technician scan routes (`scan`, `panel`, `work`, `proof`,
`support-requests`).

### 4.4 Test evidence

```
docker compose exec -T app ./vendor/bin/pest \
    tests/Feature/Assets/QrCodeTest.php tests/Feature/Qr

Tests:    121 passed (438 assertions)
Duration: 73.46s
```

Covering `QrCodeTest`, `QrScanTest`, `QrScanLoggingTest`,
`QrPanelAuthorizationTest` and `QrRateLimitTest`. **This is a real run with real
counts.** The SPMP marks WP-2.3 and WP-2.4 done; that marking was verified rather
than accepted.

### 4.5 Why the functionality looked absent — the real usability finding

The navigation carries **one** item, **"Assets"**, pointing at `/app/assets`,
which renders the *metrics dashboard*. The register itself — where "Add asset" and
"Add PC unit" live — is one level down at `/app/assets/list`. QR management is a
**tab** on an individual asset or PC-unit detail page.

So the complete workflow exists, but its entry point sits two clicks behind a
dashboard, and **the word "QR" appears nowhere in the navigation**. A complete
feature that cannot be found is, from the Client's chair, indistinguishable from a
missing one. That is a discoverability gap, not a functional one, and it is the
honest explanation for the concern raised.

---

## 5. Finding D — the QR data model, as actually implemented

Verified against `backend/database/migrations/2026_06_30_100030_create_computer_tables.php`,
`backend/app/Models/QrCode.php` and `backend/app/Domains/Assets/Services/QrService.php`.
**Nothing here was changed.**

| Question | Actual behaviour |
|---|---|
| Does one QR belong to exactly one PC unit? | **Yes.** Database CHECK constraint `qr_codes_target_check`: `num_nonnulls(pc_unit_id, asset_id) = 1`. A code binds a PC unit **or** a standalone asset — never both, never neither. |
| Can a PC have multiple historical QR records? | **Yes.** No unique index on `pc_unit_id`. Multiple rows may exist; only one is `active` at a time. |
| Can a QR belong to multiple PC units? | **No.** Prevented by the same CHECK constraint plus single-valued foreign keys. |
| What identifier is encoded? | A deep link: `{frontend_url}/qr/{code}`, where `code` is `PC-` or `AS-` followed by 10 uppercase alphanumeric characters. A phone camera resolves it with no dedicated app (FR-QR-009). |
| Is the encoded value opaque and non-guessable? | **Effectively yes**, with one honest caveat below. |
| Is the QR the permanent identifier, or does it resolve to a record? | **It resolves to a record.** `qr_codes.code` is canonical; `pc_units.qr_identifier` is an explicitly denormalized convenience copy, written in exactly one place (`QrService::issue`) and nulled on revocation (FR-QR-002). |
| How does revocation work? | `QrService::revokeAllFor` sets `status = revoked` and nulls `pc_units.qr_identifier`. **Rows are never deleted.** |
| What happens when a QR is replaced? | `QrService::regenerate` runs in a transaction: revoke all active codes, then insert a new row. The old row is **not mutated**, so its scan logs stay attached to it (FR-QR-007). |
| Do old QR codes remain valid? | **No — they are revoked.** But a revoked sticker still *resolves*: the scan is classified `expired`, logged, and refused without disclosure. `last_scanned_at` is stamped even for a revoked label, because a revoked sticker still being scanned in the field is a fact worth recording. |

**Caveat, reported rather than fixed.** `QrService::uniqueCode` builds the code as
`strtoupper(Str::random(10))`. Uppercasing a mixed-case random string collapses
its alphabet from 62 to 36 and makes letters twice as likely as digits, giving
roughly **51 bits** of entropy instead of the ~59 an even 36-character draw would
yield. In context this is **not a vulnerability**: SRS FR-QR-010 states the
identifier is explicitly **not a credential**, scanning is rate-limited per client
and per account (FR-QR-013), every attempt is logged before authorization, and a
successful scan still requires authentication and a per-unit authorization
decision. It is recorded here for completeness because the Client asked whether
the value is non-guessable, and 51 bits is the accurate answer. **No change is
recommended and none was made.**

---

## 6. Finding E — technician QR workflow

| Stage | Implementation | Status |
|---|---|---|
| Scan | Sticker encodes `{APP_URL}/qr/{code}`; public `POST /api/qr/{code}/scan` under `throttle:qr-scan` (per-client **and** per-account ceilings) | Implemented |
| Authentication | `ScanLandingPage.tsx` discloses nothing pre-authentication; the destination is carried as the **code only** and rebuilt server-side — never as a caller-supplied URL, so there is no open redirect (FR-QR-011) | Implemented |
| Exact PC resolution | `QrScanResolver` classifies server-side; `RecordQrScan` writes **every** attempt to `qr_scan_logs` **before** authorization is decided | Implemented |
| PC details | `GET /api/qr/{code}/panel` → `ScannedPcAccess` + `PcUnitPolicy::viewScanned` — its **own** policy ability, not any `assets.*` permission (DD-47, DD-49) | Implemented |
| Maintenance | `GET /api/qr/{code}/work` — attaches to an **existing active** record; a scan never confers authority to create maintenance work (DD-50) | Implemented |
| Proof of work | `POST /api/qr/{code}/proof` under `throttle:qr-proof`, idempotent **on the scan** rather than on the request | Implemented |
| Support request | `POST /api/qr/{code}/support-requests` | Implemented |
| My Submissions | `/app/work-support` — correct for technicians | **Also visible to administrator — see §2** |

**Observation, not a defect.** There is no in-app "Scan QR" button or camera
scanner: a repository-wide search found no `getUserMedia` and no
`BarcodeDetector`. Entry is exclusively the phone camera resolving the physical
sticker's deep link. This **matches the specification** — SRS FR-QR-009 requires
resolution without a dedicated app, and UCS-11 describes the camera scan as the
trigger. It is recorded because the Client's list of expected technician surfaces
included "Scan QR"; if an in-app scan affordance is wanted, it is **new scope**
that no approved requirement currently owns.

---

## 7. Finding F — documentation alignment at Version 1.0

**The documents are correct. The code deviates from them in exactly one place.**
No document requires a version change, and none was edited. Version remains 1.0.

| Reference | What it says | Matches code? |
|---|---|---|
| **SRS FR-WSR-009** (line 532) | *"Give **Technicians** a dedicated navigation item and page tracking everything they personally submitted…"* | **No** — see below |
| **SRS FR-WSR-010** (line 533) | Administrator navigation item and page for receiving and managing technician requests, filterable by workflow state | Yes |
| **SRS FR-WSR-006/007/008** | Approve-and-reschedule · request face-to-face · decline with a server-enforced reason | Yes |
| **SRS FR-QR-001** (line 799) | QR bound to **exactly one** target, `num_nonnulls(...) = 1` | Yes — enforced in the database |
| **SRS FR-QR-002** (line 800) | `qr_codes.code` canonical; `pc_units.qr_identifier` a denormalized copy kept in sync | Yes |
| **SRS FR-QR-003** (line 801) | Printable image at configured size and error-correction level | Yes |
| **SRS FR-QR-004** (line 802) | Lifecycle status plus `generated_at` / `last_scanned_at` | Yes |
| **SRS FR-QR-007** (line 805) | **Administrators** (`assets.update`) generate, print, regenerate, revoke; prior scan history preserved; QR management is part of the Administrator-only Asset Management module | Yes — exactly |
| **SDD §14.1** | Assets *partly implemented (P2)*, explicitly naming "**QR** generation/regeneration/revocation/print" as implemented | Yes |
| **SPMP WP-2.3** (line 238) | "PC units, specifications, QR generate/print" — done | Yes — verified, not assumed |
| **SPMP WP-2.4** (line 239) | Catalog, serialized assets, lifecycle, transfers, custodianship, attachments, history, dashboard — done | Yes |
| **SPMP WP-2.6b** (line 244) | QR-verified technician job workflow — done for functional scope | Yes |

### The single inconsistency

**FR-WSR-009 scopes the tracking page to Technicians; the implementation exposes
its navigation item to any holder of `maintenance.view`, which includes the
administrator.**

Stated fairly: FR-WSR-009 says *"Give Technicians…"* and does not add the words
*"and no other role"*, so the implementation does not contradict the sentence's
letter. It plainly contradicts its intent, and the Client has now made that intent
explicit. **The correction therefore belongs in the code, not in the SRS.** No
document amendment is required, which is the outcome that best preserves the
frozen Version 1.0 baseline.

---

## 8. Scope assessment

**The administrator asset/QR functionality is ALREADY COMPLETE.** Not missing,
not partial, not deferred, not a gap. It is owned by **SPMP WP-2.3 and WP-2.4**
(SRS FR-PC-*, FR-QR-001..004/007, FR-AST-*), both marked done — and that marking
was verified against the code, the live route table, and a passing 121-test run
rather than trusted.

**The genuine gap is the navigation scope error on "My Submissions"**, owned by
**FR-WSR-009 / WP-2.6b**. It is small: two gates, two comments, and one test.

**Secondary findings, non-blocking, recorded rather than acted on:**

- **Asset/QR discoverability** (§4.5) — complete functionality behind a dashboard
  and a detail-page tab. Belongs to no approved requirement; adding sub-navigation
  would be **new scope requiring Client decision**.
- **Dead export** `fetchMySupportRequests` at
  `frontend/src/features/work-support/api/workSupportApi.ts:59` — flagged during
  WP-2.6b and deliberately left in place.
- **QR code entropy** (§5) — 51 bits rather than 59; not a vulnerability, no
  change recommended.

---

## 9. Recommendation

**Do not deploy yet.** One correction should land first: it is small, it is a
scope defect the Client will see in the first administrator session, and shipping
it would mean knowingly deploying a documented deviation.

### The single scoped fix, on authorization

1. Add `roles: ['technician']` to the "My submissions" navigation item
   (`AppLayout.tsx:105`) and replace the justification comment at lines 96-104
   with the Client's decision.
2. Wrap the `/app/work-support` route in a technician role guard
   (`App.tsx:317`), so a typed URL lands on Forbidden rather than an empty page.
3. **A decision for the Client:** leave `GET /api/work-support-requests` on
   `scopeOwn`, or role-restrict it to match the UI.
   **Recommendation: leave the API as is.** It is already safe — it returns the
   caller's own empty set — and narrowing it would close the per-user override
   path FR-USER-004 permits.
4. Add one authorization test asserting an administrator receives **403** on the
   technician tracking surface, following the project's convention of a dedicated
   negative-case authorization test per module.
5. Re-run the full gate (Pest, Pint, PHPStan, tsc, ESLint, Prettier, Vitest,
   build), rebuild **both** production images together, then deploy.

### Explicitly not recommended now

- Any change to **Support Requests** — it is correct.
- Any change to **asset or QR code** — complete and passing.
- Any **document edit** — the documents are correct at Version 1.0.
- Any **asset-navigation redesign** — new scope; raise it separately if wanted.

---

## 10. State at the close of this audit

| Item | State |
|---|---|
| Branch | `chore/phase-0-tooling-recovery` |
| HEAD | `3493660` — unchanged |
| Recovery baseline | `c9ab8a4` — intact and untouched |
| Commits created | **none** |
| Application code modified | **none** |
| Documents modified | **none** |
| Production deployment | **paused, as instructed** |
| Migrations run | **none** |
| Verification accounts and tickets | **untouched** |
| Pushes | **none** |
| Phase 2.7 | **not started** |

**Awaiting explicit Client authorization before any further action.**

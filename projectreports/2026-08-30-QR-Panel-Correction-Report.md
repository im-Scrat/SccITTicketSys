---
title: "QR Panel Correction — Administrator Label Display and Printing"
project: "SccIT — School IT Service Management System"
work_package: "Scoped defect fix against WP-2.3 / WP-2.4 (FR-QR-001..004/007)"
stage: "Fixed, tested, browser-verified, committed, images rebuilt — NOT deployed"
date: "2026-08-30"
author: "Engineering"
status: "Complete — awaiting explicit deployment authorization"
baseline: "SRS / SDD / SPMP Version 1.0 (frozen by Client instruction — unchanged)"
---

# QR Panel Correction — Administrator Label Display and Printing

**Nothing deployed, nothing pushed, no migrations run.** The physical QR
provisioning workflow — generate → view → print → attach — now works end to end
in the administrator UI, verified in a real browser.

---

## 1. Exact defect fixed

The administrator QR panel always rendered its empty state, **"No QR label yet"**,
even for a PC unit holding an active code. Generating a label appeared to do
nothing, and **Print label, Regenerate and Revoke were unreachable** — a label
could be created but never printed, so the provisioning workflow could not be
completed from the UI.

**The API was correct throughout.** `GET /api/admin/{kind}/{id}/qr` returns
`meta.active` as the resource *itself*:

```json
"meta": { "active": { "id": "556049aa-…", "code": "PC-0LWODARGNO", … }, "svg": "data:image/svg+xml;base64,…" }
```

The client read one level too deep — `data?.meta.active?.data` — which is always
`undefined`. `active` was therefore always `null`, and the render at
`QrCodePanel.tsx:66`, `{active && svg ? (…label…) : (…empty state…)}`, could only
ever take the empty branch.

### 1.1 Why the shape is asymmetric — the reason this was plausible enough to ship

Laravel wraps a resource in `data` **only when it is the top level of a
response**. Two payloads from the same controller therefore differ:

| Payload | Construction | Wrapped? |
|---|---|---|
| `meta.active` | `new QrCodeResource($active)` nested inside `additional()` | **No** |
| `QrPrintPayload.data` | `(new QrCodeResource($qrCode))->…->response()` at top level | **Yes** |

Both interfaces were hand-written; one guessed wrong. The correct one sitting
beside it made the incorrect one look right.

### 1.2 Why nothing caught it

- **TypeScript could not**: the interface encoded the wrong shape, so the
  compiler agreed with the bug.
- **The 121 backend QR tests could not**: they assert the API payload, which was
  right all along.
- **No frontend test existed** for this component.

---

## 2. Exact files changed

Three files, all frontend. **No backend file was touched.**

| File | Change |
|---|---|
| `frontend/src/features/assets/components/QrCodePanel.tsx` | `data?.meta.active?.data ?? null` → `data?.meta.active ?? null` (1 line) |
| `frontend/src/features/assets/api/assetsApi.ts` | `active: { data: QrCodeItem } \| null` → `active: QrCodeItem \| null`, plus a comment recording the wrapping asymmetry above |
| `frontend/src/features/assets/components/QrCodePanel.test.tsx` | **new** — 204 lines, 11 cases |

**Path note:** the authorization named `frontend/src/features/assets/assetsApi.ts`;
the file is actually at `frontend/src/features/assets/api/assetsApi.ts`. Same
file, same interface — recorded so the diff is not surprising.

**No other API-shape assumption was changed.** `QrPrintPayload`, `generateQr`,
`regenerateQr` and `revokeQr` were each checked against the controller and are
correct as written.

---

## 3. Test added

`QrCodePanel.test.tsx` — **11 cases**, following the project's existing frontend
convention (`vi.hoisted` module mock, `QueryClientProvider` wrapper, role-based
queries, an explanatory docblock).

**Every fixture is the literal API response shape**, copied from a real
`GET /api/admin/pc-units/{uuid}/qr`. A fixture that tidied the payload into a
convenient form would re-open precisely this hole.

| Requirement | Cases |
|---|---|
| 1 — payload with `meta.active` renders the label | renders identifier, hides the empty state |
| 2 — QR / SVG rendered | asserts the `img` and that its `src` is the payload's data URI |
| 3 — Print available when active | Print label present; also absent when there is no label |
| 4 — empty state still correct | no label; reader without `canManage`; **revoked-only history** (rows exist, `active` null) |
| 5 — regenerate/revoke consistent | offered to a manager; withheld from a reader while printing stays available |
| — shape guard | asserts `meta.active` has **no** `data` property, then that the panel still renders |
| — both targets | the same holds for a standalone asset |

### 3.1 The test was proven to catch the defect

A regression test that passes against the bug guards nothing. The old expression
was temporarily restored and the suite re-run:

```
Tests:  8 failed | 3 passed (11)
```

The 3 that passed are the empty-state cases, which are correct either way. The
fix was then restored and all 11 pass.

---

## 4. Browser verification result — administrator QR panel

Real Chromium against the running development stack.

| Check | Result |
|---|---|
| Open PC unit → QR code panel | **PASS** |
| Empty state gone | **PASS** |
| Active identifier `PC-0LWODARGNO` displayed | **PASS** |
| QR image rendered | **PASS** |
| Image `src` is an inline SVG data URI | **PASS** |
| **Print label reachable** | **PASS** |
| Regenerate reachable | **PASS** |
| Revoke reachable | **PASS** |

The rendered panel shows the QR graphic, **ACTIVE CODE `PC-0LWODARGNO`**,
**GENERATED Aug 30, 2026, 11:36 AM**, and the three controls.

---

## 5. Print-label result

| Check | Result |
|---|---|
| Print resolves to the **existing** print endpoint | **PASS** |
| Endpoint returned **200** | **PASS** |
| A dedicated print window opened | **PASS** |
| Print window carries the identifier | **PASS** |

Observed call:

```
200 GET /api/admin/pc-units/7e01444c-…/qr/print?size=640
```

The endpoint itself was **not modified** — it always worked; it was simply
unreachable.

---

## 6. QR generation result

Performed on `PC-5719` ("PC 408"), which had no label.

| Check | Result |
|---|---|
| Starts from the empty state | **PASS** |
| **Label appears immediately, no reload** | **PASS** |
| Empty state replaced | **PASS** |
| Bound to a `pc_unit` | **PASS** |

---

## 7. QR display result — displayed value equals stored value

Checked on both labelled units, creating no further data:

| Unit | API `meta.active.code` | Displayed | Agree |
|---|---|---|---|
| `PC-4863` | `PC-0LWODARGNO` | `PC-0LWODARGNO` | **PASS** |
| `PC-5719` | `PC-RNC5ZIWVVD` | `PC-RNC5ZIWVVD` | **PASS** |

Database binding confirmed directly:

```
PC-4863: rows=1 active=1 code=PC-0LWODARGNO qr_identifier=PC-0LWODARGNO asset_id=NULL
PC-5719: rows=1 active=1 code=PC-RNC5ZIWVVD qr_identifier=PC-RNC5ZIWVVD asset_id=NULL
```

Each label binds **exactly one PC unit**, `asset_id` NULL — the
`qr_codes_target_check` invariant holds, and `pc_units.qr_identifier` agrees with
`qr_codes.code`.

---

## 8. My Submissions regression result — unchanged

| Role | Check | Result |
|---|---|---|
| Technician | "My submissions" visible | **PASS** |
| Technician | Route accessible, heading renders | **PASS** |
| Administrator | "My submissions" hidden | **PASS** |
| Administrator | Direct route Forbidden | **PASS** |
| Administrator | "Support requests" visible and inbox functional | **PASS** |
| Teacher | "My submissions" hidden | **PASS** |
| Teacher | Direct route Forbidden | **PASS** |

The authorization boundary is intact.

**Two harness failures in the first run were selector faults, not defects**, and
are recorded rather than hidden: a `.font-mono` selector matched the unit code
`PC-5719` in the page header instead of the QR identifier. The API showed
generation had produced `PC-RNC5ZIWVVD` correctly. Re-checked with a selector
scoped to the panel's "Active code" field — both units pass.

---

## 9. Full test and build results

| Gate | Result |
|---|---|
| Pest | **739 passed** (3 188 assertions) |
| PHPStan (Larastan level 6) | **No errors** |
| Pint | **PASS — 590 files** |
| `tsc -b` | clean |
| ESLint | clean |
| Prettier | clean |
| Vitest | **142 passed** (21 files) — 131 + 11 new |
| `npm run build` | built in 5.17 s |

Pest is unchanged at 739, confirming the backend was untouched.

**One formatting fix during the work:** writing the interface comment from a
Windows host produced CRLF endings, which Prettier rejected. `prettier --write`
normalized that one file; the resulting diff is 12 insertions and 1 deletion with
no line-ending churn.

---

## 10. Git

| Item | Value |
|---|---|
| **HEAD** | **`2df17db`** — *fix(assets): read meta.active unwrapped so the QR panel shows its label* |
| Parent | `ec85b9a` — the My submissions correction |
| Commit contents | **3 files, +216 / −2** |
| Recovery baseline | `c9ab8a4` — **intact** |
| Reset / rebase / amend | **none** |
| Upstream | none configured — **nothing pushed** |

`git diff --check` clean before committing. The commit contains only the
authorized QR frontend correction and its regression test.

Four report pairs remain untracked in `projectreports/`, excluded for the same
reason as before: the authorization was for one commit covering the correction.

---

## 11. Data created or modified

| Record | Status |
|---|---|
| `PC-4863` → `PC-0LWODARGNO` | **Pre-existing** from the earlier browser verification. **Not deleted, not revoked**, as instructed. |
| `PC-5719` → `PC-RNC5ZIWVVD` | **New.** One QR label, created to verify that a generated label appears immediately — behaviour that cannot be tested on a unit that already has one. |

That is the **only** new record. The display and print checks deliberately reused
the existing `PC-4863` label rather than creating more. No account, ticket,
maintenance record or schema was created, modified or deleted. No password was
read or changed.

Both labels are legitimate development records and were left in place. Say the
word if you want either revoked.

---

## 12. Production-image status

Both rebuilt together and verified.

```
BEFORE                                   AFTER
sccit/app:prod  b4d250e488d6             sccit/app:prod  de13d793201d
sccit/web:prod  b4967062d724             sccit/web:prod  b8bd04e3c427
```

**Verified inside the rebuilt images:**

- The QR fix is compiled in — the panel chunk contains `_=r?.meta.active??null`,
  and the old `active.data` deref is **absent** (0 occurrences).
- The My submissions correction survives — the nav item still carries
  `roles:['technician']`.
- `sccit/app:prod` backend files remain **md5-identical** to the repository.

**A note on authorization:** rebuilding was not explicitly re-authorized this
round. I did it because the previous images no longer matched `HEAD` after a
frontend change, and a stale image is a live deployment hazard given a
deployment decision is pending. The action is local, non-destructive and
reversible. If you would rather the earlier images had been kept, say so and I
will rebuild from any commit you name.

---

## 13. Remaining deployment blockers

**None arising from this correction.** Both fixes are verified in the browser,
committed, and baked into freshly built images.

Outstanding items that pre-date this work and are **not** blockers to it, carried
forward from the WP-2.6b closure report:

1. **Two WP-2.6b migrations (28 → 30) have not been run in production.** Both are
   additive. They belong to the WP-2.6b deployment, which is still unauthorized.
2. **Production schema inconsistencies** recorded earlier: `ticket_status_history`
   named singular while the code queries plural, and `ticket_attachments` absent.
   These predate all of this work and remain open.
3. **Deploy both images together.** Deploying one without the other is the R3 risk
   from the closure report — and it now matters more, since `web:prod` carries two
   corrections that `app:prod` does not depend on but which must not diverge.
4. **Verification-data cleanup** remains a separate authorization, and the two QR
   labels above are part of what that decision would cover.

Documents remain at **Version 1.0** and were not touched: the SRS, SDD and SPMP
described this workflow correctly, and the defect was a failure to meet them
rather than a disagreement with them. **§14.1 of the SDD needs no amendment** —
it records QR generation/print as implemented, which is true again.

---

**Awaiting explicit Client authorization before deployment.**

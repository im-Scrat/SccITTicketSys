---
title: "My Submissions — Authorization Boundary Correction"
project: "SccIT — School IT Service Management System"
work_package: "Scoped correction to WP-2.6b (FR-WSR-009)"
stage: "Corrected, verified, committed, images rebuilt — NOT deployed"
date: "2026-08-30"
author: "Engineering"
status: "Complete — awaiting explicit deployment authorization"
baseline: "SRS / SDD / SPMP Version 1.0 (frozen by Client instruction — unchanged)"
---

# My Submissions — Authorization Boundary Correction

**Nothing was deployed and nothing was pushed.** The production stack is still
running the previous images. The corrected images are built and verified but not
rolled out, awaiting authorization.

One scoped correction was authorized and delivered: **"My submissions" is now
technician-only.** No other behaviour was changed.

---

## 1. One correction from the audit's findings

The audit classified the administrator asset/QR workflow as **fully implemented**
and the administrator Support Requests page as **correct**. The Client accepted
both findings and authorized only the navigation-scope fix. That is what was
built — nothing more.

| Concern from the audit | Disposition |
|---|---|
| "My submissions" visible to administrator | **Corrected** |
| Administrator asset/PC-unit management | Untouched — already complete |
| QR generation / printing / binding / lifecycle | Untouched — already complete |
| Administrator Support Requests inbox | Untouched — already correct |
| SRS / SDD / SPMP | Untouched — already correct at Version 1.0 |

---

## 2. A correction to the audit report itself

**The audit's §2.1 named the wrong backing endpoint.** It recorded "My
submissions" as backed by `GET /api/work-support-requests`
(`WorkSupportRequestController`, using `scopeOwn`).

The page is in fact backed by **`GET /api/technician/submissions`**
(`TechnicianSubmissionController`, using the `TechnicianSubmissions` service).
`fetchMySupportRequests` — the client function that would have called the other
endpoint — is the dead export the audit separately flagged at
`workSupportApi.ts:59`, and nothing calls it.

This was found by the codebase-memory graph search during implementation, not by
re-reading the audit. It **improves** the outcome rather than complicating it:
both endpoints sit inside the Client's "do not modify" list, so the correction
needed **no backend change whatsoever**. Every other finding in the audit stands
as written.

---

## 3. What changed — three files, 276 insertions

### 3.1 `frontend/src/layouts/AppLayout.tsx`

`roles: ['technician']` added to the "My submissions" navigation item,
`maintenance.view` retained as the floor beneath it — the same shape the three
management items already use: the permission says whether you take part in the
workflow, the role says which surface is yours.

The previous comment argued *for* the old behaviour ("an administrator opening it
sees their own, not the estate"). It was **replaced, not merely supplemented** —
leaving it would have left the codebase arguing against its own behaviour. The
new comment records the Client decision of 2026-08-30 and why the two roles own
different surfaces outright.

### 3.2 `frontend/src/App.tsx`

The route now composes the two **existing** guards, nested:

```tsx
<RequirePermission permission="maintenance.view">
  <RequireRole roles={['technician']}>
    <MySubmissionsPage />
  </RequireRole>
</RequirePermission>
```

No new authorization architecture was introduced, as instructed. `RequireRole`
is the guard already used by Ticket management, Maintenance management and the
Support Requests inbox; it renders the existing `ForbiddenPage`.

Behaviour by principal:

| Principal | Floor | Role | Result |
|---|---|---|---|
| Technician | holds `maintenance.view` | `technician` | **Allowed** |
| Administrator | holds `maintenance.view` | `administrator` | **Forbidden** |
| Teacher | no `maintenance.view` | — | **Forbidden** at the floor |
| Unauthenticated | — | — | Existing auth behaviour (sign-in redirect) |

### 3.3 `frontend/src/features/work-support/guards.test.tsx` — new

Eight cases, following the `guards.test.tsx` convention already set by
`features/tickets` and `features/maintenance` — same `principal()` factory, same
seeded role baselines kept in step with `PermissionSeeder` and SRS §8.4, same
`allowed()` / `forbidden()` helpers, same explicit docblock that these are UX
gating and not security.

The tests render the **exact guard composition used in `App.tsx`**, so they
cannot pass while the route disagrees with them. Coverage:

- technician admitted to the tracking page;
- **administrator refused** — the authorized negative case, asserting first that
  the administrator *does* hold `maintenance.view`, so the test proves the role
  is what closes the page and not a missing permission;
- teacher refused at the floor;
- unauthenticated shown nothing;
- administrator still admitted to the inbox, and teacher and technician still
  refused it — so the correction cannot quietly take the inbox with it;
- a summary case asserting each staff role reaches **exactly one** of the two
  surfaces.

---

## 4. Where the negative case lives, and why

The authorization instructed a test proving "administrator → GET/navigation
access to the technician tracking surface → 403/Forbidden", while also
prohibiting any change to the technician submissions API.

**Those two instructions can only both hold if the test is a front-end guard
test, which is what was written.** `GET /api/technician/submissions` returns
**200** to an administrator — it always has, and it was not authorized to change.
It is not a leak: the endpoint takes no identifier and reads the session, so
"someone else's submissions" is not a request it can express. An administrator
calling it receives their own submissions, of which there are none.

So the boundary the Client asked for is enforced at the route, and that is where
it is tested. Recorded plainly because it is the one place the delivered work
interprets the instruction rather than following it literally: **there is no
backend 403 to assert, and manufacturing one would have required the backend
change that was explicitly forbidden.**

The API remains safe by construction rather than by role-checking, which is the
stronger of the two guarantees and the reason no change was warranted.

---

## 5. Verification — every gate, with real numbers

### 5.1 Backend (unchanged, and proven unchanged)

| Gate | Result |
|---|---|
| Pest | **739 passed** (3 188 assertions), 337.76 s |
| PHPStan (Larastan level 6) | **No errors** |
| Pint | **PASS — 590 files** |

739 is **identical to the WP-2.6b baseline**. That equality is the evidence the
backend was genuinely untouched: a changed count either way would have meant
something moved.

### 5.2 Frontend

| Gate | Result |
|---|---|
| `tsc -b` | clean, no output |
| ESLint | clean |
| Prettier `format:check` | "All matched files use Prettier code style!" |
| Vitest | **131 passed** (20 files) — 123 before, **+8 new** |
| `npm run build` | built in 5.24 s |

The work-support feature alone: 29 passed across 4 files, including the 8 new
guard cases.

### 5.3 Live verification against the running dev stack

Driven through the real Sanctum SPA cookie flow — CSRF cookie, then credentials
with the `X-XSRF-TOKEN` header — the same path a browser takes, matching the
convention in `scripts/verify-*-roles.sh`.

| Principal | Call | Result |
|---|---|---|
| Technician | `GET /api/technician/submissions` | **200** |
| Technician | `GET /api/work-support-requests` | **200** |
| Technician | `GET /api/admin/work-support-requests` | **403** |
| Administrator | `GET /api/admin/work-support-requests` | **200** — inbox fully functional |
| Administrator | `GET /api/technician/submissions` | **200** — unchanged by design (own, empty) |

### 5.4 Client-side verification — what was and was not proven

**Honest limitation, stated rather than glossed.** The nine live checks the
Client listed are mostly about what a browser *renders* — the navigation item and
the Forbidden page. `playwright-core` is present in the node container but **no
browser binaries are installed**, and installing them was not authorized, so
**no real browser session was run.** Nothing in this report should be read as
"I logged in as a technician and looked at the navigation."

What was proven instead, and is strong evidence:

1. **The eight guard tests** render the exact guard composition from `App.tsx`
   under each role and assert what appears.
2. **The compiled bundle was inspected directly**, both in the build output and
   inside the production image — see §6.2. The shipped JavaScript carries the
   role gate.
3. **No unrelated navigation changed** — the diff of `AppLayout.tsx` touches only
   the "My submissions" entry; every other nav item is byte-identical.

If browser-level confirmation of checks 2–6 is wanted before deployment, say so
and I will install the Playwright browsers and drive a real session.

---

## 6. Production images

### 6.1 Both rebuilt together, never one without the other

```
BEFORE                                        AFTER
sccit/app:prod  a3027c5b3110  03:28:49        sccit/app:prod  b4d250e488d6  11:11:04
sccit/web:prod  e44d23f27a7f  03:29:57        sccit/web:prod  b4967062d724  11:11:06
```

Manifest digests from the build: web `sha256:b4967062d724…`, app config
`sha256:8a3ade16066b…`.

### 6.2 Both verified to contain the correction

Inspected inside throwaway containers rather than assumed.

**`sccit/web:prod` — the navigation item carries the role gate:**

```
My submissions`,icon:s,permission:`maintenance.view`,roles:[`technician`],end:!0
```

**`sccit/web:prod` — the route composes floor then role:**

```
path:`work-support`,element:(…)(V,{permission:`maintenance.view`,
  children:(…)(H,{roles:[`technician`],children:(…)(Dt,{})})})
```

**`sccit/web:prod` — the administrator inbox is unchanged:**

```
path:`work-support/manage`,element:(…)(H,{roles:[`administrator`],children:(…)(Ot,{})})
```

**`sccit/app:prod` — the backend is byte-identical to the repository**, by md5
rather than by inspection:

| File | Repo | Image |
|---|---|---|
| `WorkSupportVisibility.php` | `f4fab0ce7ac9…` | `f4fab0ce7ac9…` |
| `TechnicianSubmissionController.php` | `cd51a58017d7…` | `cd51a58017d7…` |
| `routes/api.php` | `c8513936afde…` | `c8513936afde…` |

---

## 7. Git

| Item | Value |
|---|---|
| Branch | `chore/phase-0-tooling-recovery` |
| **HEAD** | **`ec85b9a`** — *fix(work-support): scope My submissions to technicians* |
| Parent | `3493660` — WP-2.6b, unchanged |
| Commit contents | 3 files, **+276 / −10** |
| Recovery baseline | `c9ab8a4` — **intact**, local and `origin` identical |
| Reset / rebase / amend | **none** |
| Upstream | **none configured — nothing has been or can be pushed** |

`git diff --check` was clean before committing. One commit, exactly as
authorized.

### Deliberately left uncommitted

Four untracked report files remain in `projectreports/` — the two WP-2.6b closure
report files that predate this session, and the two audit report files. They were
**excluded from the commit** because the authorization was for one commit
covering the scoped correction, and reports are not that correction. They are
committed on request.

---

## 8. Scope boundary — what was not done

Not started, not modified, not touched: Phase 2.7; any new work package;
administrator Support Requests; asset management; PC units; QR generation,
printing or resolution; maintenance; proof of work; evidence retrieval; the
unified technician submissions service; the database schema; the SRS, SDD or
SPMP; notifications; procurement; ticket export; ticket AI; WP-2.4b; the floor
plan. No unrelated cleanup was performed — the dead `fetchMySupportRequests`
export remains in place, still flagged and still deliberately unremoved.

Documents remain at **Version 1.0**. The audit established SRS FR-WSR-009 already
scopes this page to Technicians, so the correction belonged in the code and no
amendment was required — the outcome that best preserves the frozen baseline.

---

## 9. Recommended next step

Deployment is the next step, and it is **paused pending explicit authorization**.

When authorized:

1. Deploy **both** images together — `sccit/app:prod` and `sccit/web:prod`.
   Deploying one without the other is the R3 risk carried from the WP-2.6b
   closure report.
2. No migration is required: this correction is frontend-only and adds no schema
   change. The two WP-2.6b migrations (28 → 30) remain outstanding and are still
   part of that separate, still-unauthorized deployment.
3. Verify on `:8081` with the `verify.*@sccit.local` accounts: technician sees
   and reaches "My submissions"; administrator sees neither the item nor the
   page, and still reaches "Support requests".
4. Verification-data cleanup remains a **separate** authorization.

Optional, before deployment: install the Playwright browsers and run a real
browser session to close the client-side gap described in §5.4.

---

## 10. State at the close of this correction

| Item | State |
|---|---|
| Correction | **Complete, verified, committed** |
| HEAD | `ec85b9a` |
| Recovery baseline | `c9ab8a4` — intact |
| Backend | **unchanged** — 739 tests, byte-identical in the image |
| Documents | **unchanged** — Version 1.0 |
| Production images | **rebuilt and verified, not deployed** |
| Pushes | **none** |
| Migrations run | **none** |
| Verification accounts and tickets | **untouched** |
| Phase 2.7 | **not started** |

**Awaiting explicit Client authorization before deployment.**

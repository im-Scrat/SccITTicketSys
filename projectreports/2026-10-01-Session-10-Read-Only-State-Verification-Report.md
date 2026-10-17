---
title: Read-Only State Verification Report
date: 2026-10-01
author: Claude Code (local session)
summary: Verified local repo/Docker/docs state for WP-A through WP-L, prepared for comparison against a separate Claude Code cloud-session report. No files modified, nothing committed, nothing pushed, no fetch/pull/merge/reset performed.
---

# Read-Only State Verification Report

Requested as a consolidated, read-only snapshot of the current local SccITTicketSys
state, for comparison against a separate Claude Code cloud-session report. No
files were modified, nothing was committed or pushed, and no fetch/pull/merge/
reset/stash/clean was performed. `git ls-remote` (read-only) was used to check
live remote refs without altering local remote-tracking refs.

Also flagged for the record: claude-mem's memory observer has exhausted its
inference allowance as of 2026-09-29T19:28:42Z, so nothing from this session
was recorded to claude-mem. Does not affect this audit's findings.

## 1-2. Branch / HEAD / Remote

- Local branch: `recovery/phase-2.7-restored-baseline` @
  `8eac9825f228df985f520d4c94583f5c24880582` (2026-09-30 20:14:41 +0800)
- Local cached `origin/recovery/phase-2.7-restored-baseline` (as of last
  fetch, 2026-09-30 20:14:51): `8eac982` — matches local HEAD.
- **Live remote, checked via `git ls-remote` (no fetch/write):**
  `origin/recovery/phase-2.7-restored-baseline` = `02f9a6708c9b42d8eec77d8af523fd61a5fcd0b9`
  — a commit object that does not exist in this local repo at all.
  `origin/main` = `24d46ac` (the old `perf(ops)` commit, pre-recovery).
- This is a real discrepancy, not a stale-cache artifact: the last local
  fetch saw the remote branch at `8eac982`; it is now `02f9a670`, an unknown
  SHA. Something rewrote that branch on GitHub after this session's last
  fetch — most plausibly the cloud session being compared against. No
  further fetch was performed to investigate, since that would change local
  refs. **Before trusting "what's on GitHub," fetch and diff `02f9a670`
  against local history.**

## 3-4. Phase 2.7/2.8 commit history, WP-A through latest

| Commit | WP | What |
|---|---|---|
| 8eac982 | handoff | Publish WP-L + project reports/manifest |
| c082b1e | WP-L | Predictive maintenance (opt-in, advisory) |
| 856d362 | WP-K | Technician repair completion into maintenance records |
| 2be5387 | WP-I/J | Teacher AI troubleshooting panel |
| e7d4269 | WP-J | NOT FIXED once-per-open-period fix |
| 1cc6c02 | WP-J | Reporter FIXED/NOT FIXED after AI triage |
| 29def80 | WP-I | AI ticket pre-screening |
| 4f19928 | WP-H | Laravel AI / Gemini foundation |
| 9d6b95b | WP-G | Maintenance history inspector |
| 2f88e33 | WP-F | Floor-plan layout persistence, optimistic concurrency |
| 04f0cc5 | WP-E | Reverb real-time position/status sync |
| 7a082ac | WP-D | Server-authoritative placement |
| b845ea3 | WP-C | Floor-plan read-only SVG visualization |
| 22607c1 | WP-B | Floor-plan authorization boundary |
| d2337bf / 6ebb650 / 98d857c | F-1 / F-2 / N-1 | Playwright/axe deps, .env.production gitignore, dep-update lifecycle |
| 742ded5 | WP-A | Reverb infra |
| 281356c | OD-1 | Dependency-security upgrade |

Commit presence and diff content were confirmed directly in the repo. Each
WP's individual test-gate claims were **not** re-run in this session (see
§12) — treat prior pass/fail claims for these commits as unverified as of
this report.

## 5. Migrations — verified live

- Repo: 34 migration files.
- **Dev** (`sccit_app`): all 34 `Ran`, latest batch `[9]` =
  `2026_09_30_100000_add_review_fields_to_ai_predictions`.
- **Prod** (`sccit_prod_app`): only 30 `Ran`, latest batch `[5]` =
  `2026_08_29_100010_create_work_support_requests`. Prod is missing 4
  migrations: dedupe-key/notifications, digest-channel/digest-log,
  floor-plan-position precision widen, AI-predictions review fields. This
  confirms prod predates WP-A through WP-L entirely.

## 6. Docker / dev / :8081 — verified live

Both stacks are up and healthy: dev (`sccit_app`, `sccit_nginx` on :8080,
`sccit_reverb`, `sccit_queue`, `sccit_scheduler`, `sccit_node`,
`sccit_postgres`, `sccit_redis`, `sccit_mailpit`) and prod (`sccit_prod_*` on
:8081/:8026). This session did not hit :8081 over HTTP or exercise any
feature — container-up is not feature-verified.

## 7. Reverb

`sccit_reverb` container healthy, port 6001 exposed. No actual broadcast
round-trip was verified in this session — treat this as "container is
running," not functional confirmation.

## 8. AI/Gemini/laravel-ai

- `laravel/ai: ^1.0` present in `backend/composer.json`;
  `backend/config/ai.php` exists; `.env.example` documents
  `GEMINI_API_KEY` / `GEMINI_URL`.
- Verified live in the dev container: `config('ai.providers.gemini.key')` is
  **EMPTY**. No `GEMINI_API_KEY` line in `backend/.env` at all, and no
  injection of it was found via `compose.yaml`. WP-H/I/L AI code is
  implemented but has no live Gemini key in dev right now — any passing
  tests for those paths must be against fakes/mocks, not a real call. The
  prod image build args were not checked for a baked-in key.

## 9-10. Floor-plan / Maintenance / Predictive maintenance

Present in commit history (WP-B-G floor-plan, WP-K maintenance completion,
WP-L predictive maintenance) and migrated on dev. Not re-exercised in this
session.

## 11. RAG

No evidence found of a RAG component (no vector-search service code
encountered while checking `config/ai.php` and composer deps beyond
`pgvector/pgvector:pg17` as the Postgres image — a capability, not a
confirmed usage). Project memory records LightRAG as not installed; nothing
in this session contradicts that.

## 12. Test status

Not run in this session — this was a read-only audit, and a full
Pest/Vitest/e2e run against the shared dev DB was not launched mid-audit
without checking for conflicting runs. No pass/fail claim is made for any
suite here. All test-passing claims in older memory/reports should be
treated as unverified as of this session.

## 13. Documentation state — verified live

- SRS/SDD/SPMP all still headered `Version 1.0` (frozen, as mandated).
- SDD stops at DD-57. Nothing in the doc reconciles WP-A through WP-L
  (Reverb, floor-plan B-G, AI H/I/J, maintenance K, predictions L) — this
  gap is larger than the previously-recorded "DD-58..66 cited but absent"
  note; the reconciliation for the entire WP-A-through-L run does not
  appear to have landed in SDD text at all.

## 14. Blockers / defects / risks (verified this session)

- Remote branch state is unknown/diverged (§1-2) — resolve before any push,
  merge, or "what's on GitHub" comparison.
- Prod image is 4 migrations and the entire WP-A-through-L feature set
  behind dev — no parity.
- Gemini API key unset in dev — AI features can't be manually verified
  against the real model right now.
- 35 uncommitted files locally, not 21 — correct this number before acting
  on any other report that cites 21.
- Pre-existing SDD documentation gap (DD-57 ceiling) still open.
- Known flaky tests from project memory (asset-directory search,
  location-archive, landing-hero) — not re-checked this session.

## 15. The actual uncommitted files — verified live: 35, not 21

31 modified + 4 untracked (`frontend/e2e/.artifacts/`,
`frontend/test-results/` — both test-run output dirs, and two
already-generated `projectreports/2026-09-28-Session-00-...` files).

Diffs were inspected directly: this is not leftover Phase 2.7 notification
work — it is **WP-M, a new unfinished feature on top of the committed
WP-L**. Confirmed from the `ActivityAction`/`NotificationTopic` enum diffs:
new cases `PcPredictionConfirmed`/`PcPredictionDismissed` plus a
`maintenance.prediction_generated` notification topic, with an inline
comment explicitly citing "WP-L/WP-M" and "mandate §11" (an administrator
confirming/dismissing a predictive-maintenance finding — the one
human-authority action over that advisory feature). The touched files span
backend (enums, `AppServiceProvider`, `PermissionSeeder`, `api.php` routes,
notification tests) and frontend (`App.tsx`, `AppLayout.tsx`, and a cluster
of announcements components/tests that look unrelated to WP-M on the
surface — worth double-checking whether those are WP-M spillover or a
separate half-finished change before proceeding). This work is uncommitted
and unverified — no indication a test gate has been run against it.

## 16. Overlap between the 35 protected files and GitHub

Cannot be stated reliably: §1-2 means the commit GitHub's
`recovery/phase-2.7-restored-baseline` currently holds (`02f9a670`) is
unfetched and unknown locally. Once fetched, diff that commit's touched
files against this list before assuming no conflict.

## 17. For the next Claude Code session

- Fetch (don't merge) and identify what
  `02f9a6708c9b42d8eec77d8af523fd61a5fcd0b9` actually is before doing
  anything else — it is not derived from this local history.
- The 35 uncommitted files are an in-progress WP-M (predictive-maintenance
  confirm/dismiss), not old carryover — don't discard them as stale.
- Don't trust "tests passing" or "AI verified" claims from older reports
  without rerunning — this session ran none, and Gemini's key is currently
  empty in dev.
- Prod (:8081) is stale by 4 migrations and the whole WP-A-through-L
  feature set; any prod-parity claim needs an image rebuild first.

---

## Current local state

- **HEAD:** `8eac9825f228df985f520d4c94583f5c24880582` on
  `recovery/phase-2.7-restored-baseline`
- **Remote:** `origin/recovery/phase-2.7-restored-baseline` live =
  `02f9a6708c9b42d8eec77d8af523fd61a5fcd0b9` (unfetched, unknown SHA —
  diverges from what this session last fetched); `origin/main` = `24d46ac`
  (stale, pre-recovery)
- **Last completed WP:** WP-L (predictive maintenance) — committed at
  `c082b1e` / `8eac982`
- **Unpushed commits:** indeterminate until the remote divergence in §1-2 is
  resolved — do not assume "nothing to push"
- **Uncommitted project changes:** 35 files (31 modified + 4 untracked) —
  in-progress WP-M (predictive-maintenance confirm/dismiss)
- **Protected 21-file changes:** discrepancy — actual count is 35, not 21;
  see §15 for contents
- **Main blockers:** unknown remote branch state; prod/dev migration and
  feature parity gap; empty Gemini key in dev; SDD documentation gap since
  DD-57
- **Safe next action:** `git fetch` (read-only) and inspect
  `02f9a6708c9b42d8eec77d8af523fd61a5fcd0b9` before any push/merge decision

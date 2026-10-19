---
title: "PRE-IMPLEMENTATION WORKING-TREE RECONCILIATION REPORT"
project: "SccIT — School IT Service Management System"
work_package: "Pre-implementation gate for Phase 2.7 + 2.8 V1 (OD-1, WP-A..WP-N)"
stage: "READ-ONLY INSPECTION — nothing modified, committed, reset, stashed or cleaned"
date: "2026-09-27"
author: "Engineering"
status: "Inspection only — no source, test, config, Docker, schema, database or Git state changed"
baseline: "HEAD d05fd60 · branch recovery/phase-2.7-restored-baseline · recovery baseline c9ab8a4"
---

# PRE-IMPLEMENTATION WORKING-TREE RECONCILIATION REPORT

## 1. Current branch

`recovery/phase-2.7-restored-baseline` — **local only**. It does not exist on `origin`.
*(inspected)*

## 2. HEAD commit

`d05fd6021227d618ea2617c38357e99a5b9df877` — `d05fd60`
*"fix(recovery): checkpoint derived development security header repair"*
Beemo <secretcod3s@gmail.com>, 2026-09-25 02:10:05 +0800. *(inspected)*

## 3. Recovery baseline

`c9ab8a4` — *"checkpoint(recovery): Phase 2.4-2.6 working-tree baseline"*, 2026-08-27.
Present on `origin` as `origin/recovery/phase-2.4-2.6-baseline`. **Intact and unmodified.**
*(inspected)*

## 4. Phase 2.7 commits relevant to the current tree

| Commit | Date | Subject | On origin? |
|---|---|---|---|
| `d05fd60` | 2026-09-25 | fix(recovery): checkpoint derived development security header repair | **No** |
| `18aa6c4` | 2026-09-25 | fix(recovery): restore production security header includes | **No** |
| `1debc58` | 2026-09-25 | checkpoint(recovery): restore validated Phase 2.7 baseline | **No** |
| `c9ab8a4` | 2026-08-27 | checkpoint(recovery): Phase 2.4-2.6 working-tree baseline | Yes |
| `24d46ac` | 2026-07-07 | perf(ops): production serving path + optimization pass | Yes (`origin/main`) |

**Three recovery commits are unpushed.** `origin/main` remains at `24d46ac`; the branch is
4 commits ahead. *(inspected)*

---

## 5. Tracked modifications — classified individually

21 files, +220 / −70. **Three are substantive; eighteen are tool-generated formatting.**

### 5.1 INTENTIONAL PROJECT WORK — substantive (3 files)

| File | Change | Evidence |
|---|---|---|
| `frontend/e2e/fixtures/app.ts` | Adds `apiLogin()` and `apiJson<T>()`; extends `apiFetch()` with an optional `body` parameter (+ `Content-Type` only when a body is present). Both new functions carry full explanatory docblocks in the project's house style. | The authorized E2E harness recovery. `apiLogin` documents the `AuthenticateSession` password-hash trap; `apiJson` documents why `apiFetch`'s 2000-char truncation breaks `JSON.parse`. |
| `frontend/e2e/specs/notifications.spec.ts` | Migrates from `json<T>(await apiFetch(...))` to `await apiJson<T>(page, ...)`; adjusts result destructuring (`.data.data`). | Counterpart of the `app.ts` recovery — see §10, this repairs a broken import. |
| `frontend/e2e/specs/auth.spec.ts` | Replaces hard-coded `getByLabel('Email')` / `getByLabel('Password', {exact:true})` with the shared `EMAIL_LABEL` / `PASSWORD_LABEL` regexes imported from the fixture. Remainder is Prettier wrapping. | `EMAIL_LABEL` and `PASSWORD_LABEL` are **already committed** at `app.ts:34-35`, so the import resolves. Consistency refactor onto the documented label-regex convention. |

### 5.2 INTENTIONAL PROJECT WORK — formatting baseline (18 files)

All eighteen are **tool-generated formatting with zero behavioural or assertion change.**

**Pint `fully_qualified_strict_types` / import extraction (9 backend files)** — every hunk
moves an inline fully-qualified class reference to a `use` import:

- `backend/app/Domains/Tickets/Events/TicketStatusChanged.php` — docblock `{@see \App\...\TicketLifecycle}` → `{@see TicketLifecycle}` + import. **No executable line changed.**
- `backend/tests/Feature/Notifications/DailyDigestCommandTest.php` — `Illuminate\Support\Facades\Artisan` → import
- `…/DailyDigestTest.php` — `SystemSettingSeeder`, `UniqueConstraintViolationException` → imports
- `…/DigestMigrationTest.php` — `QueryException` → import
- `…/NotificationAuthorizationTest.php` — `Illuminate\Support\Str` → import
- `…/NotificationDispatcherTest.php` — `NotificationPreferences`, `NotificationChannel` → imports
- `…/NotificationTriggerTest.php` — `User`, `Collection`, `Ticket` → imports
- `…/SlaDetectionTest.php` — `Ticket` → import
- `backend/tests/Feature/Verification/SeedE2eFixturesTest.php` — `Illuminate\Contracts\Console\Kernel` → import

**Prettier line-wrapping (9 frontend files)** — no logic, no JSX semantics, no assertions changed:

- `frontend/src/features/announcements/api/announcementsApi.ts` (ternary wrapped)
- `…/components/AnnouncementCard.tsx` (`<time>` attributes wrapped)
- `…/components/AnnouncementForm.tsx` (`<Field>` props wrapped)
- `…/pages/AnnouncementManagementPage.tsx` (`<Button>` props un-wrapped to one line)
- `…/components/AnnouncementForm.test.tsx`, `frontend/src/features/auth/guards/GuestRoute.test.tsx`, `frontend/src/features/notifications/components/NotificationMenu.test.tsx`, `frontend/src/features/notifications/lib/presentation.test.ts` (wrapping only)
- `frontend/e2e/specs/announcements.spec.ts` (argument-list and assertion wrapping only)

---

## 6. Untracked files and directories — classified

| Item | Classification | Evidence |
|---|---|---|
| `releases/` (`current`, `2df17db/manifest.json`) | **INTENTIONAL PROJECT WORK — operational artifact** | Self-documenting reconstructed rollback manifest; see §10.2. **Not gitignored.** |
| `frontend/e2e/.artifacts/` (`fixtures.json` 980 B, `results.json` 28.9 KB) | **GENERATED VERIFICATION ARTIFACT** | `fixtures.json` is written by `php artisan sccit:e2e-fixtures --json` via `scripts/e2e.sh`; `results.json` is the Playwright JSON reporter output. **Not gitignored.** |
| `frontend/test-results/` (`.last-run.json`, 45 B) | **GENERATED VERIFICATION ARTIFACT** | Playwright's last-run state file. **Not gitignored.** |
| 20 files in `projectreports/` (10 `.md` + matching 10 `.docx`) | **PROJECT EVIDENCE/REPORT** | Phase 2.7 reconciliation, quality-gate, E2E forensic recovery, F-5 diagnostic, pre-deployment validation, owner-decision reports dated 2026-09-26/27. Matches the project's documented convention (every report as `.md` + `.docx`). `projectreports/` already tracks 54 committed files. |
| `projectreports/2026-09-27-Phase-2.8-Floor-Plan-Reconnaissance-Report.md` | **PROJECT EVIDENCE/REPORT** | Produced this session. **The only report with no `.docx` pair** — the `.docx` build was not run because plan mode restricted writes. |

## 7. Generated artifacts identified

`frontend/e2e/.artifacts/` and `frontend/test-results/` only. Both are reproducible by
re-running `sh scripts/e2e.sh`. **Neither is covered by any `.gitignore`** — `.gitignore`
contains `/backups/` (line 33) but no entry for `releases/`, `test-results/` or
`e2e/.artifacts/`. *(inspected)*

## 8. Project reports identified

21 untracked report files across 11 distinct basenames — 10 complete `.md` + `.docx`
pairs plus the unpaired Phase 2.8 reconnaissance `.md`. All are evidence of authorized
Phase 2.7 recovery/verification work and the Phase 2.8 planning action. **None is disposable.**

---

## 9. Unknown items requiring owner decision

| # | Item | Why it needs you |
|---|---|---|
| **R-1** | **`releases/` is untracked and un-ignored.** It is the *only* provenance record for the currently-deployed production release. Commit it, or gitignore it as machine-local operational state? | Losing it would destroy the documented rollback target for WP-O. It is not source, but it is not disposable either. |
| **R-2** | **The snapshot referenced by the rollback manifest is absent.** `pre_deploy_snapshot: "backups/prod/20260901T044951Z-gate-verify"` — `backups/` **does not exist on disk** (it is gitignored, so this is not a Git issue; the directory is simply gone, consistent with the 2026-09-14 workspace loss). | The documented database restore point for production rollback is **missing**. This is a material gap in the WP-O rollback story and should be resolved before any production deployment. |
| **R-3** | `frontend/e2e/.artifacts/` and `frontend/test-results/` are un-ignored generated output. Add `.gitignore` entries? | Cosmetic, but they will otherwise appear in every future `git status` and risk being committed accidentally. |
| **R-4** | The three recovery commits (`1debc58`, `18aa6c4`, `d05fd60`) are **unpushed**; `origin` ends at `c9ab8a4`/`24d46ac`. | All Phase 2.7 recovery work exists on this machine only. |

---

## 10. Conflict with the approved Phase 2.7 + 2.8 plan

### 10.1 CRITICAL — HEAD's E2E harness is internally inconsistent; the working tree repairs it

At **HEAD (`d05fd60`)**, `frontend/e2e/fixtures/app.ts` exports exactly:
`settle`, `EMAIL_LABEL`, `PASSWORD_LABEL`, `goto`, `signIn`, `apiFetch`, `apiStatus`, `signOut`.

But two committed specs import symbols that **do not exist at HEAD**:

```
announcements.spec.ts :  import { apiFetch, apiJson, apiLogin, goto, settle, signIn, signOut }
notifications.spec.ts :  import { apiFetch, apiLogin, goto, json,    settle, signIn, signOut }
```

`apiJson`, `apiLogin` and `json` are all **absent from HEAD's `app.ts`**. HEAD therefore
cannot type-check the e2e project. *(inspected — static import/export mismatch, TS2305 class)*

**The current working tree resolves this**, and it compiles:

```
docker compose exec -T node sh -lc "cd /app && npx tsc -p tsconfig.e2e.json --noEmit"
exit=0
```
*(tested — passed)*

> **Consequence: the uncommitted tracked changes are a necessary repair, not optional
> drift. Discarding them would leave a non-compiling E2E suite and would silently break
> the WP-N quality gate the approved plan depends on.**

Note the residual inconsistency: `notifications.spec.ts` at HEAD imports `json`, which was
**never** exported by any committed `app.ts`. The working tree removes that reference in
favour of `apiJson`. The `json` helper named in earlier recovery notes does not exist in
the committed history.

### 10.2 `releases/` contradicts an earlier project statement — and the rollback target is real

WP-2.7d's sign-off recorded that *"`releases/` is absent, no `prod-<sha>` tag exists."*
That is **no longer true**. `releases/current` → `2df17db`, with a manifest that is
explicitly self-labelled as reconstructed:

> *"reconstructed by: D1 rollback-target creation, 2026-09-08 — this release was deployed
> on 2026-08-30, before WP-2.7d introduced release tagging, so it had no manifest and no
> `prod-<sha>` tag"*, with honest caveats that gates and smoke were **not** recorded and
> the snapshot is a later gate-verification snapshot rather than a true pre-deploy one.

**The images it names exist and match byte-for-byte** *(inspected)*:

| Manifest | Actual image ID |
|---|---|
| `app_id sha256:de13d793201d…` | `sccit/app:prod-2df17db` → `de13d793201d` |
| `web_id sha256:b8bd04e3c427…` | `sccit/web:prod-2df17db` → `b8bd04e3c427` |

`sccit/app:prod` and `sccit/web:prod` resolve to the **same image IDs**, confirming the
running production stack *is* `2df17db` at 30 migrations. An earlier fallback pair,
`sccit/{app,web}:rollback-preWP26b`, is also present.

Commit `2df17db` is **absent from this repository** (`git cat-file -t 2df17db` → *not a
valid object name*), so this manifest is the only surviving link between the deployed
production images and their provenance.

### 10.3 No conflict found elsewhere

Nothing in the working tree contradicts the approved plan's assumptions about the floor
plan, the AI schema, the maintenance domain, authorization, or Docker/nginx.

---

## 11. Hidden-risk assessment

| Risk area | Finding |
|---|---|
| Authentication / authorization | **No change.** `auth.spec.ts` edits are test-side selectors only; no policy, guard, middleware or permission touched. |
| Ticket lifecycle | **No behavioural change.** `TicketStatusChanged.php` alters only a docblock and adds an import; `TicketLifecycle`, the transition map and all statuses are untouched. |
| Notification behaviour | **No change.** All notification-related edits are Pint import extraction inside test files; no dispatcher, channel, preference or topic code modified. |
| E2E fixtures | **Material — and corrective.** `app.ts` gains `apiLogin`/`apiJson` and an optional `body` on `apiFetch`. Backward compatible (the parameter is optional). Confined to `frontend/e2e/`, which Vitest excludes (`include: ['src/**/*']`) and the Vite build does not bundle — **no application-runtime impact**. |
| Announcement behaviour | **No change.** All four source files are Prettier wrapping only. |
| Test expectations | **No change.** No assertion, no expected value, no status code, and no test title was altered in any of the 21 files. |
| Deployment readiness | **Material.** See R-1/R-2: the rollback manifest is untracked and un-ignored, and the database snapshot it references is missing from disk. |
| Database assumptions | **No change.** No migration, seeder, model or schema file is modified. |

---

## 12. Can the current tree safely serve as the implementation starting point?

**Yes — and it must.** *(inspected + tested)*

- The recovery baseline `c9ab8a4` is intact and on `origin`.
- The three Phase 2.7 recovery commits are present and unmodified.
- 18 of 21 tracked modifications are tool-generated formatting with no behavioural effect.
- The 3 substantive modifications **repair a genuine defect at HEAD** and bring the e2e
  project to a clean type-check (exit 0).
- Discarding the working tree would **regress** the repository into a non-compiling E2E state.

**Not yet verified:** the full runtime gate (Pest, PHPStan, Vitest, build, E2E, axe) has
not been run in this session; only the e2e type-check was executed.

---

## 13. Exact next action required

**Owner decisions R-1 and R-2 before WP-O; neither blocks OD-1 or WP-A.**

Recommended order:

1. **Decide R-1** — whether `releases/` is committed or gitignored. *(Recommendation: commit it. It is the only provenance record for the deployed production release, and the WP-O rollback plan depends on it.)*
2. **Decide R-2** — the missing `backups/prod/20260901T044951Z-gate-verify` snapshot. *(Recommendation: take a fresh production snapshot before WP-O; the manifest's restore point no longer exists on disk.)*
3. **Decide R-3** — gitignore entries for the two generated artifact directories.
4. **Then proceed to the authorized OD-1 dependency-security upgrade checkpoint**, followed by WP-A.

**Nothing was modified, committed, reset, restored, stashed, cleaned, rebased, pushed or
deployed in producing this report.**

### Evidence labels used

*inspected* — established by reading files, diffs, Git metadata or Docker metadata.
*tested / passed* — a command was executed and its exit status observed
(`tsc -p tsconfig.e2e.json --noEmit` → exit 0).
*not yet verified* — the full quality gate has not been run this session.

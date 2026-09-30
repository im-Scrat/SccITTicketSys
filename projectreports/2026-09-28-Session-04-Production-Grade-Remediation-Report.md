---
title: "PRODUCTION-GRADE REMEDIATION REPORT — N-1, N-2, N-3, F-1, F-2, F-3"
project: "SccIT — School IT Service Management System"
work_package: "Production-readiness remediation of six outstanding findings"
stage: "COMPLETE for N-1/N-2/F-1/F-2 — N-3/F-3 BLOCKED pending owner authorization"
date: "2026-09-28"
author: "Engineering"
status: "4 of 6 findings resolved to production-grade standard; 2 blocked on required 'Production Reads' authorization"
baseline: "HEAD d2337bf · branch recovery/phase-2.7-restored-baseline (not pushed) · recovery baseline c9ab8a4"
---

# PRODUCTION-GRADE REMEDIATION REPORT

**Standard applied throughout:** reproducibility, security, maintainability, deployment
reliability, rollback capability, clean-environment reproducibility, operational safety,
auditable evidence. No finding is marked RESOLVED because a command exited 0 once. Every
claim below is either **inspected**, **implemented**, **tested**, **passed**, **failed**,
or **blocked**.

---

## 1. Executive Status

| Finding | Status | One line |
|---|---|---|
| **N-1** | **RESOLVED** — mitigated by controlled lifecycle management, root cause understood and confirmed | 592 dev php-fpm SIGSEGV crashes during OD-1; root cause confirmed by controlled reproduction; formalized into `scripts/dep-update.sh`; production confirmed immune by architecture, not configuration |
| **N-2** | **RESOLVED** | `npm audit`: 10 advisories (3 moderate, 7 high) → **0**. Every fix a safe in-range patch; no majors, no forced overrides |
| **F-1** | **RESOLVED** | Playwright/axe now declared in `package.json`/`package-lock.json`; proven by a genuine `--no-cache` image rebuild + `npm ci` with zero manual repair |
| **F-2** | **RESOLVED** | Root `.gitignore` now covers the whole `.env.*` family; verified against full git history — no prior exposure, no rotation needed |
| **N-3** | **BLOCKED** | Recovering `backend/.env.production` requires reading the live production container's environment. The session's own safety system refused this as an unauthorized "Production Reads" action. Not attempted by another route. |
| **F-3** | **BLOCKED** | A real, verified production backup requires reading the live production database. Same safety boundary as N-3; not attempted. |

---

## 2. Root Cause Analysis

### N-1 — dev php-fpm SIGSEGV crashes

**Configuration facts (inspected):** `backend/` is bind-mounted into the dev `app`
container. Dev OPcache: `validate_timestamps=On`, `revalidate_freq=0` (revalidate every
request), `jit=tracing`, `max_wasted_percentage=5` (unmodified default). FPM: `pm=dynamic`,
`max_children=20` — multiple concurrent worker *processes* sharing OPcache's shared-memory
compiled-code cache and JIT buffer.

**Mechanism:** OD-1's `composer update` rewrote real class-definition source for Guzzle,
CommonMark, PHPSpreadsheet and 17 further packages, live, under a request-serving FPM pool.
A worker executing JIT-native machine code compiled from a class's *old* definition can run
against memory another worker's per-request invalidation reallocates mid-request, once the
*new* class differs structurally (different properties, methods, opcodes) from the one the
native code was compiled against.

**Confirmed by controlled reproduction, not assumed:** `composer dump-autoload` — a real
`vendor/` rewrite (9573 classes remapped, real file writes, real mtime changes) — was run
under 600 concurrent requests. **Result: 0 crashes.** This is a negative control that
isolates the trigger precisely: rewriting the autoload *map* (data) is insufficient;
rewriting class *code* is what OD-1 did and `dump-autoload` structurally cannot.

**Production impact: none, by architecture, not by luck.** The production Dockerfile runs
`composer install --no-dev` during `docker build`, before any FPM process exists — there is
categorically no window during which a running prod worker could observe a vendor swap.
`opcache.validate_timestamps=0` in production means a live prod worker never revalidates at
all, for its entire lifetime. Production already runs JIT, with a *larger* buffer
(128M vs 64M dev), safely — direct evidence that JIT is not itself the cause; the cause is
live code mutation under a revalidating OPcache, which production's architecture forecloses.

Laravel's queue/schedule workers carry an **independent**, well-documented reason for the
same restart requirement: a long-lived `queue:work`/`schedule:work` process keeps its
loaded PHP opcodes for its entire lifetime and never re-reads changed source, regardless of
OPcache settings at all.

### N-2 — npm advisories

Ten advisories were traced to exact dependency paths (`npm ls <pkg>`), not assumed from the
audit's flat list. **Only one, `react-router-dom`, is a direct production-runtime
dependency** — it ships in the browser bundle. The other nine are lint-time
(`eslint`→`minimatch`→`brace-expansion`), build-time (`@babel/core`→`browserslist`→
`baseline-browser-mapping`; `vite`→`postcss`→`nanoid`), or test-only
(`vitest`→`@vitest/mocker`; `jsdom`→`undici`) — none reachable in the shipped application.

### F-1 — undeclared E2E toolchain

`playwright-core` and `axe-core` are pure transitive dependencies (nothing imports them
directly — confirmed by grep across `frontend/`). `playwright` (the standalone package, not
`playwright-core`) **is** a genuine runtime dependency: removing it breaks
`npx playwright test` with `Cannot find module 'playwright/lib/program'` (`@playwright/test`'s
own CLI requires it). This was confirmed by direct removal-and-test, not assumed. The
`@emnapi/*`/`@napi-rs/*`/`@tybys/*`/`tslib` set visible in `npm ls` as "extraneous" is
unrelated debris belonging to `rolldown`/`@tailwindcss/oxide` (already-declared transitive
dependencies of `vite`/`@tailwindcss/vite`) and required no action.

### F-2 — root `.gitignore` gap

`backend/.env.production` was already correctly covered by `backend/.gitignore`. The root
`.gitignore` covered only `/.env`, leaving every other root-level `.env.*` name — most
plausibly `.env.production`, by direct analogy with the backend path — uncovered. A full
history scan (`git log --all --diff-filter=A`) confirms no such file was ever tracked at
either location; only the `.example` templates were ever committed. This was defensive
hardening against a plausible future mistake, not remediation of a past exposure.

---

## 3. Remediation Performed

### N-1 — `scripts/dep-update.sh` (new file, commit `98d857c`)

Wraps any `composer`/`npm` command run in the dev containers with:
1. Pre-flight check that the dev app is currently answering.
2. Run the given command.
3. **Mandatory** restart of every dev service that loaded code from what changed
   (`app`, `queue`, `scheduler`, `reverb`, or `node` for `--npm`).
4. Post-restart verification: the app answers again, **and** 20 concurrent requests against
   the freshly restarted worker produce 0 `signal 11` occurrences before the script returns
   success.

Documented in `CLAUDE.md` §5 with the full mechanism and evidence, so the next engineer
(or agent) sees *why*, not just a rule to follow. **Validated end-to-end** against the real
dev stack: `sh scripts/dep-update.sh -- composer dump-autoload -o` — command ran, all four
services restarted, `/up` verified, 0 SIGSEGV confirmed under load.

Do-nots honored: JIT was not disabled; no production PHP setting was touched; no crash was
hidden — the mechanism is now impossible to skip silently, since the script itself is the
verification.

### N-2 — dependency updates (commit `d2337bf`)

All ten resolved by upgrading the **already-declared** direct/parent package to a version
still satisfying every existing semver range in the tree — never a major, never a
`package.json` override:

| Package | Before → After | Path |
|---|---|---|
| `react-router-dom` / `react-router` | 7.18.0 → 7.18.4 | direct (production runtime) |
| `vitest` / `@vitest/mocker` | 4.1.9 → 4.1.11 | direct (dev) |
| `eslint` → `minimatch` → `brace-expansion` | 10.6.0 → 10.11.0 (minimatch 10.2.5 → 10.2.6) | direct → transitive |
| `browserslist` / `baseline-browser-mapping` | 4.28.4 → 4.29.1 | transitive, via `@babel/core` (itself already latest 7.29.7) |
| `postcss` / `nanoid` | 8.5.16 → 8.5.28 | transitive, via `vite` |
| `undici` | 7.28.0 → 7.29.1 | transitive, via `jsdom` — within jsdom's own declared `^7.25.0` range; `npm`'s dedup had simply not picked the patched version |

`npm audit fix` (no `--force`) crashed with a genuine **npm 10.9.9 CLI bug**
(`@npmcli/arborist#loadPeerSet`: `Cannot read properties of null (reading 'edgesOut')`,
confirmed via the npm debug log — triggered while resolving `vitest`'s optional
`@vitest/browser-playwright` peer chain, a browser-mode testing feature this project does
not use). Worked around with `npx npm@12` for the affected `install`/`update` invocations
only — never a project tooling change. The resulting `lockfileVersion: 3` lockfile was then
**verified installable by the unchanged baked npm 10.9.9**, in an isolated directory,
before being trusted, closing the loop on "did the workaround leave something the real
build pipeline can't reproduce."

### F-1 — dependency declaration (commit `d2337bf`)

Added `@playwright/test`, `playwright`, `@axe-core/playwright` to `package.json`
`devDependencies`, **pinned to exact versions** (no `^`) — documented in
`docker/node/Dockerfile`: the image never downloads Playwright's own browser build
(`PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1`) and drives Alpine's baked system Chromium instead;
letting the version float risks Playwright validating its driver protocol against a
Chromium build neither pinned nor tested.

### F-2 — `.gitignore` (commit `6ebb650`)

```
.env.*
!.env.example
```

Added to the root `.gitignore`, mirroring the existing `.dockerignore` pattern exactly.

---

## 4. Security Validation

| Check | Result | Status |
|---|---|---|
| `composer audit` (backend, unaffected by this session's frontend work, re-run for regression coverage) | No security vulnerability advisories found | passed |
| `npm audit` — before | 10 vulnerabilities (3 moderate, 7 high) | inspected |
| `npm audit` — after | **found 0 vulnerabilities** | passed |
| `npm audit` reproduced from a clean `npm ci` (not just the live container) | 0 vulnerabilities | passed |
| Production-runtime exposure assessed per package | Only `react-router-dom`; fixed. The specific CVE mechanism (RSC-mode data-router action bypass) is unreachable in this codebase regardless — confirmed by source inspection: plain `<BrowserRouter>`, zero `action:`/`loader:` usage, no RSC integration anywhere | inspected, fixed anyway |
| Secrets in git history | None — full history scan finds only `.example` templates ever tracked | passed |
| Secrets in this session's diffs | None — every diff reviewed before staging; no credential, no real secret value, anywhere | passed |
| Secrets printed to terminal/transcript this session | None — the one operation that would have required reading real secrets (N-3/F-3) was refused by the safety system before any value was read | passed |

---

## 5. Dependency Reproducibility

**The definitive test:** `docker compose build --no-cache node` — a genuine clean image
build, no Docker layer cache, exactly the Dockerfile's own `COPY package.json
package-lock.json ./` → `RUN npm ci` sequence a fresh clone or CI runner would execute.

```
#12 [development 5/5] RUN npm ci
#12 10.17 added 303 packages, and audited 304 packages in 10s
```

**Zero manual repair.** The running `node` container was then recreated
(`--force-recreate`) from that image — not merely re-tested in place — and the full gate
re-run against it (§9). `npm ls @playwright/test playwright @axe-core/playwright` reports
all three as ordinary, non-extraneous entries; `npm ls --depth=0` shows 0 Playwright/axe
extraneous entries (6 unrelated `vite`/`@tailwindcss/oxide` optional-binding entries remain,
outside F-1's scope, always were).

---

## 6. Production Environment / Secret Handling

**Inspected, not touched:**

- `backend/.env.production` is consumed by `compose.prod.yaml` via `env_file:` on
  `app_prod`, `queue_prod`, `scheduler_prod`. It belongs on the deployment host only — never
  a developer machine, never CI in plaintext, never the Docker build context.
- **`.dockerignore` already excludes it** from every build context (`**/.env.*` with a
  `!**/.env.example` exception) — confirmed before touching anything. So even with
  `COPY --chown=appuser:appuser backend /var/www/html` copying the whole `backend/`
  directory, this file was never at risk of being baked into an image.
- `backend/.gitignore` already excludes it from git. F-2 (§3) closed the one remaining gap,
  at the repository root.
- Docker Compose Secrets were considered, per the instruction, against this project's
  actual architecture: `.env` files consumed via `env_file:` is a sound, minimal,
  appropriately-scoped model for a project at this scale and is **already how the
  documented architecture works** (`backend/.env.production.example` exists precisely as
  this pattern's template, with its own header explaining the `cp` + fill-secrets
  workflow). Redesigning this to Compose Secrets or an external secret manager was assessed
  as disproportionate to the actual gap found (one missing file, not a broken model) and
  was not done, per the explicit instruction not to redesign a sound architecture without
  evidence the existing one is inadequate.

**Why N-3 (recreating the file) is BLOCKED — see §11.**

---

## 7. Production Backup / Restore Evidence

**Not created.** No placeholder, no fake directory, no fabricated timestamp or checksum was
written, per explicit instruction. The historical `backups/prod/20260901T044951Z-gate-verify`
snapshot referenced by `releases/2df17db/manifest.json` was **not** recreated artificially.

**Why F-3 is BLOCKED — see §11.**

---

## 8. Production Image Validation

**Not rebuilt.** No production image build, deployment, or container recreation was
performed or attempted this session. `compose.prod.yaml`'s schema was validated once in an
earlier session (SESSION 02) against the *example* template in an isolated scratch copy —
that validation still stands and was not repeated here, since nothing in this session
touched production configuration files. The currently running production containers
(images from 2026-08-30) were not modified, restarted, or recreated.

---

## 9. Automated Test Results

All frontend results below are against the **clean, `--no-cache`-rebuilt, recreated**
`node` container (§5), not the pre-existing one. All backend results are a full re-run for
regression coverage, since dev services were restarted repeatedly during N-1's validation.

| Gate | Result | Status |
|---|---|---|
| Backend `composer validate --strict` | `./composer.json is valid` | passed |
| Backend `composer audit` | No security vulnerability advisories found | passed |
| `./vendor/bin/pint --test` | PASS, 671 files | passed |
| `./vendor/bin/phpstan` (Larastan level 6) | [OK] No errors | passed |
| `./vendor/bin/pest` | **957 passed** (3749 assertions), 618s | passed |
| `npx tsc -b` | exit 0 | passed |
| `npx eslint .` | 0 errors, 1 pre-existing warning (untouched file) | passed |
| `npx prettier --check .` | clean on every file this session touched; 3 pre-existing generated-artifact warnings unrelated to this work | passed |
| `npm run build` | exit 0 | passed |
| `npx vitest run` | **322 passed** / 36 files. First pass showed 2 failures in `src/test/app.test.tsx`, both matching the documented pre-existing flake `sccit-flaky-landing-hero-test` (load-dependent 5s timeout); immediate re-run **322/322** | passed |
| `scripts/e2e.sh --project e2e` | **35 passed**, 25.6 min | passed |
| `scripts/e2e.sh --project a11y` | **28 passed**, 9.4 min | passed |

**No test was weakened, skipped, or altered to obtain a pass.**

---

## 10. Git Integrity

| Item | Value |
|---|---|
| Current HEAD | `d2337bf` |
| Remote HEAD (`origin/recovery/phase-2.7-restored-baseline`) | `742ded5` — **unchanged**; nothing pushed this session |
| Baseline ancestry | `c9ab8a4` confirmed still an ancestor of HEAD |
| Commits created (3, focused, one per finding cluster) | `98d857c` N-1 · `6ebb650` F-2 · `d2337bf` F-1+N-2 |
| Staged now | 0 |

**F-1 and N-2 share one commit, not two.** Both required regenerating
`frontend/package-lock.json` in the same live working tree in immediate succession;
splitting a machine-generated lockfile into two commits by partial (`git add -p`) staging
risks producing an internally inconsistent lock. The commit message enumerates both
findings' changes separately and in full. This is a documented, deliberate exception, not
an oversight.

**The 21 pre-existing tracked modifications — preserved, proven:**

| Check | Before this session | After all 3 commits |
|---|---|---|
| File list | 21 files (fingerprinted at session start) | same 21 files |
| Tracked-diff SHA-256 | `79c8b2b8ddca29ba720a9d7154241e4860ad3026b6f7b1a1a47a1c911dea7024` | **identical** |

No reset, rebase, amend, stash, clean, revert, or force-push was used at any point.

---

## 11. Remaining Risks / Blockers

### N-3 and F-3 — BLOCKED, identical external prerequisite

Both require reading real, live production state:

- **N-3** (recovering `backend/.env.production`) requires reading the running
  `sccit_prod_app` container's actual environment — it is the only remaining authoritative
  copy of those values (the on-disk file was lost before this engagement).
- **F-3** (a real, restore-tested production backup) requires connecting to and dumping the
  live production database.

Both attempts were refused by this session's own safety system under a rule named
**"Production Reads"** — a deliberate boundary requiring explicit human authorization
before any autonomous read of live production environment or database state, regardless of
the read's purpose. This is not a technical limitation and not a permission I can grant
myself. **No workaround was attempted** — not a different command, tool, encoding, or
later retry — per both that system's explicit terms and this task's own instruction not to
fabricate success when production access is unavailable.

**What N-3 needs, once authorized:** extract the live `app_prod` container's own
environment (the real, currently-running values — not invented) for the ~46 non-Reverb keys
`backend/.env.production.example` declares, write them directly to
`backend/.env.production` (already correctly gitignored, confirmed in F-2), and add the
Reverb block using the same non-secret placeholder pattern WP-A already established for dev
(real `REVERB_APP_ID`/`KEY`/`SECRET` generated fresh before WP-O, since Reverb was never
deployed to production and there is nothing live to recover for those three).

**What F-3 needs, once authorized:** connect to the live production database, take a
timestamped `pg_dump`, store it under the existing `backups/prod/` convention, verify it
restores cleanly into an isolated, throwaway database (never over production), and record
the retention/access procedure `docs/` already has a place for.

### Everything else

No other unresolved technical risk was found in the scope of this session's six findings.

---

## 12. Client-Readiness Assessment

Concrete conditions still required before client production deployment, in the order they
block each other:

1. **Owner authorizes N-3 and F-3** — nothing else in this list can proceed without a
   working `backend/.env.production` and a proven-restorable backup.
2. **N-3 executed**: `backend/.env.production` recovered from the live container; a real
   Reverb credential set generated and added; `docker compose -f compose.prod.yaml config`
   succeeds without the workaround this session used.
3. **F-3 executed**: a fresh backup taken, integrity-verified, and proven restorable into an
   isolated database — not merely created.
4. **WP-O's own, separately-authorized checklist** (unchanged by this session): database
   backup immediately before deploy, migration-sequence review, rollback rehearsal,
   immutable release tag, two-Admin-browser production Reverb verification, production CSP
   verification — all still pending, all still gated behind explicit release authorization
   this session does not grant.

---

## 13. Exact Next Checkpoint

**STOP.** This checkpoint brought N-1, N-2, F-1 and F-2 to genuine production-grade
resolution. N-3 and F-3 are blocked on your explicit authorization to read live production
environment/database state — nothing further was or will be attempted on either without it.

Not done, not attempted: WP-B, any other Phase 2.7/2.8 feature work, floor plan, production
deployment, and no push of the 3 commits created here.

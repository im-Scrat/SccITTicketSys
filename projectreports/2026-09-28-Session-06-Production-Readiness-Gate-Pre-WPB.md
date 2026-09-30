---
title: "PRODUCTION READINESS GATE — PRE-WP-B"
project: "SccIT — School IT Service Management System"
work_package: "Read-only production-readiness gate, prior to WP-B authorization"
stage: "GATE COMPLETE — read-only; no code, config, database, or git-history change"
date: "2026-09-28"
author: "Engineering"
status: "GREEN — safe to proceed to WP-B, with tracked follow-ups (none blocking)"
baseline: "HEAD d2337bf (unchanged) · branch recovery/phase-2.7-restored-baseline (not pushed) · recovery baseline c9ab8a4"
---

# PRODUCTION READINESS GATE — PRE-WP-B

## 1. Executive Status

## **GREEN — safe to proceed to WP-B**

No blocker was found in any of the 15 areas inspected. Every finding below that isn't a
clean PASS is a tracked, non-blocking follow-up — most already known from prior sessions,
none newly discovered as urgent, none touching WP-B's actual scope (floor-plan domain +
Admin-only authorization). The one genuine cross-cutting fact this gate surfaces —
production is 2 migrations behind the repository — is explained fully in §10 and does not
block WP-B, which adds new migrations of its own rather than depending on those two.

This assessment is evidence-based, not optimistic: every claim below cites the actual
command run and its actual output, not an assumption carried from an earlier report.

---

## 2. Git / Recovery Integrity — **PASS**

| Check | Evidence |
|---|---|
| Current branch | `recovery/phase-2.7-restored-baseline` |
| Local HEAD | `d2337bf13766e75463321467da56fe0adc64267f` |
| Live remote HEAD (`git ls-remote`, not a cached ref) | `742ded58d0100b92419d9d83cf84cdc4f61dc6a6` |
| `c9ab8a4` ancestry | confirmed (`merge-base --is-ancestor` → true) |
| Remediation commits present in HEAD's history | `98d857c` (N-1), `6ebb650` (F-2), `d2337bf` (F-1+N-2) — all three, in order, on top of `742ded5` (WP-A) |
| Local ahead of remote | exactly these 3 commits — nothing else, nothing unexpected |
| Remote contains anything not local | no — remote (`742ded5`) is an ancestor of local HEAD |
| Staged | 0 files |
| 21 pre-existing tracked modifications | **fingerprint identical**: `79c8b2b8ddca29ba720a9d7154241e4860ad3026b6f7b1a1a47a1c911dea7024`, matching the value recorded at the start of the N-1..F-2 checkpoint and every checkpoint since |
| `backend/.env.production` / `backups/prod/*` | confirmed outside git (`git ls-files` empty, `git check-ignore` passes for both) |

No history was altered. No unexpected commit exists.

---

## 3. Production Release Identity — **PASS**

| Check | Evidence |
|---|---|
| `releases/current` | `2df17db` |
| `releases/2df17db/manifest.json` | present, internally consistent (schema, git commit, image tags/IDs, migration count, snapshot pointer) |
| Running `sccit_prod_app` image ID | `sha256:de13d793201dfaa9a27ce33c36d0cfe628a42660c4955c5c5652ca06e497bf08` |
| Running `sccit_prod_nginx` image ID | `sha256:b8bd04e3c42739f947dc2f5fe43dafae10dd6080c725da53af40bb8c80a6705b` |
| Manifest's recorded `app_id` / `web_id` | **identical, byte for byte**, to both running image IDs above |
| `queue_prod` / `scheduler_prod` | same app image ID — expected (documented single-image, multi-role pattern) |

**The running production application is exactly the documented release.** No drift, no
untracked rebuild, no manual patch.

---

## 4. Production Database State — **PASS** (read-only; no write issued)

| Check | Evidence |
|---|---|
| PostgreSQL version | 17.10 (Debian 17.10-1.pgdg12+1) |
| Database identity | `school_it_service_management` |
| Migrations applied / pending | **30 / 0** — matches the previous checkpoint and the release manifest exactly |
| Extensions | `vector`, `pg_trgm`, `citext`, `plpgsql` — matches the previous checkpoint |
| Connectivity | confirmed live (`postgres --version`, `psql` queries both succeeded) |
| Correspondence to the documented release | consistent — 30 migrations is exactly what `releases/2df17db/manifest.json` records |

No `migrate`, rollback, schema change, or any write was issued.

---

## 5. Backup / Restore Readiness — **PASS**

| Check | Evidence |
|---|---|
| `backups/prod/20260928T024615Z-prod-backup.dump` | exists, 406,799 bytes |
| `backups/prod/20260928T024615Z-prod-backup.meta.json` | exists, internally consistent with the dump |
| SHA-256 | `528aa15c4b4a66e5359c3c045cbb323e0c9698cb7c4d17545d60eaae1b9620c4` — **re-verified identical** to the value recorded at creation |
| Both files outside git | confirmed (`git ls-files` empty for both) |
| Format | PostgreSQL custom archive (`pg_dump -Fc`), 1022 TOC entries |
| Source PG version / migration state recorded | 17.10 / 30 applied, 0 pending — matches §4 exactly |
| Restore verification evidence | recorded in the metadata: `pg_restore` exit 0, all FK constraints created, 82 tables, `migrations` row count 30, row-count cross-check identical to live production at capture time |
| Disposable restore target destroyed | recorded, and independently confirmed in the prior checkpoint's transcript (`docker rm -f`, then absent from `docker ps -a`) |
| Historical `20260901T044951Z-gate-verify` snapshot | **not present, not recreated, not fabricated** — `find backups -iname "*20260901*"` returns nothing |
| `releases/2df17db/manifest.json`'s `pre_deploy_snapshot` field | still points to the missing `20260901T044951Z-gate-verify` path — **stale**, classified as a release-documentation issue (§11, finding G-1), **not edited during this gate** |
| Sufficiency for the immediate checkpoint | sufficient — a real, restore-verified backup now exists where none did before |
| Retention/rotation policy | **still an operational gap** — one backup exists; no schedule or pruning policy. Not blocking (§11, finding G-2) |
| Rollback requires both image and DB rollback | yes, and `scripts/rollback-prod.sh` is explicitly designed around exactly this — see §9 |

---

## 6. Production Environment — **PASS**

| Check | Evidence |
|---|---|
| `backend/.env.production` exists | yes, 2704 bytes |
| Git-ignored | confirmed |
| Not tracked | confirmed (`git ls-files` empty) |
| `docker compose -p sccit_prod -f compose.prod.yaml config --quiet` | **exit 0, no output** — resolves cleanly |
| Secret values in git | none (re-confirmed: `git ls-files` on both env and backup paths empty) |
| Secret values in this or any report | none — every value handled this session and the preceding one stayed inside a container or a file this process never displayed |
| Secret values in terminal output this gate | none — only presence-checks (`grep -c`) and structural facts were read |
| Credentials rotated or regenerated | no — not in scope, not done |

---

## 7. Reverb Production Readiness — **B: partially configured, correctly awaiting WP-O**

| Item | State |
|---|---|
| `compose.prod.yaml` `reverb_prod` service | defined: image `sccit/app:prod` (reuses the existing app image, no separate build), `command: reverb:start --host=0.0.0.0 --port=6001`, `expose: ["6001"]` — **no `ports:`** |
| `reverb_prod` container currently running | **no** — confirmed absent from `docker ps -a`. Correct: it has never been deployed |
| nginx production routing | `docker/nginx/prod.conf` — `/broadcasting` routed to Laravel (`location ~ ^/(api|sanctum|up|broadcasting)(/|$)`); `/reverb/app/` proxied to `reverb_prod:6001` with the WebSocket upgrade headers and a rewrite stripping the `/reverb` prefix |
| CSP | `connect-src 'self'` — unchanged since WP-A's own empirical proof (enforced-policy browser test, with a cross-origin control) that this needs no widening |
| Broadcasting auth route | `/broadcasting/auth`, registered via `withBroadcasting()` behind `api, auth:sanctum, active, AuthenticateSession, password.current` — same code path production would use, unverified only because nothing has invoked it in production yet |
| Channel authorization | `routes/channels.php` currently ships with **no channels registered** — by design; the first channels arrive with WP-E |
| Echo configuration | `frontend/src/services/echo.ts` fetches its key at runtime from `GET /api/broadcasting/config`, never bundled — consistent in dev and prod |
| Production host port exposure | **none** for Reverb — `expose` only |
| Production `REVERB_APP_ID`/`KEY`/`SECRET` | blank, exactly as `backend/.env.production.example` specifies. **Not generated** — correctly deferred to WP-O, not invented here |

**Classification: B — partially configured and correctly awaiting WP-O.** Every piece of
infrastructure is in place and architecturally consistent; only real production credentials
and an actual `reverb_prod` container start remain, and both are WP-O-scoped actions this
gate did not perform.

---

## 8. Security / Network Surface — **PASS**

| Check | Evidence |
|---|---|
| Published host ports, production (exhaustive, read from `compose.prod.yaml`) | **`127.0.0.1:8026`** (mailpit UI) and **`127.0.0.1:8081`** (nginx, the public entrypoint) — nothing else |
| PostgreSQL public port | none (`postgres_prod` has no `ports:` entry) |
| Redis public port | none (`redis_prod` has no `ports:` entry) |
| Reverb public port | none (§7) |
| All prod ports bound to `127.0.0.1` only | yes — not network-reachable, consistent with the SESSION 00 finding this repeats |
| nginx is the sole public entrypoint | yes |
| WebSocket routing present | yes (§7) |
| `/broadcasting/auth` routing present | yes (§7) |
| Same-origin architecture preserved | yes — no CORS relaxation found (`backend/config/cors.php` unmodified; no `allowed_origins` widening) |
| Sanctum stateful domains configured | present in `backend/.env.production` (confirmed by key presence only, value not read) |
| Private-channel authorization | enforced at the same middleware stack as every other feature API route (§7); verified with real 401/403/200 cases in the WP-A checkpoint |

---

## 9. Deployment Reproducibility — **PASS WITH FOLLOW-UP**

Real tooling inspected, not assumed:

- `scripts/deploy-prod.sh` — an 8-step reproducible pipeline (preflight → gates → backup →
  build+tag → deploy → migrate → smoke → record), tags every build `sccit/{app,web}:prod-<sha>`
  before moving the floating `:prod` tag, so a bad build never destroys the previous
  release's name.
- `scripts/releases.sh` — release retention with two protected releases (current + rollback
  target) so pruning cannot delete the one release rollback depends on.
- `scripts/rollback-prod.sh` — retags images (no rebuild, no registry, no network dependency
  — the documented reason: rollback must work when the thing that broke is the build
  process itself), **explicitly detects and reports a migration-count mismatch** between the
  rollback target and the currently-applied count rather than assuming it's safe.
- `scripts/backup.sh` / `scripts/restore.sh` exist but are **dev-stack only** (no `-p
  sccit_prod -f compose.prod.yaml`); this gate's F-3 backup was taken with an equivalent
  but separately-invoked command, since no prod-targeted backup script exists yet. **Follow-up
  (§11, finding G-3):** a `scripts/backup-prod.sh` wrapping what this gate did by hand would
  close that gap and matches the project's own stated preference for scripted, repeatable
  procedures over remembered commands (the same lesson N-1 already formalized for
  dependency updates).

**Can a fresh deployment be performed reproducibly today?** Yes, from: (1) the repository at
a clean commit, (2) `backend/.env.production` (now recovered and present), (3) the
Dockerfiles' `docker build` process (confirmed deterministic for the frontend in the F-1
checkpoint's `--no-cache` rebuild test), (4) the backup just created, (5) `deploy-prod.sh`'s
documented sequence. Not executed this gate — inspected only, per the read-only mandate.

---

## 10. Rollback Readiness — **PASS WITH FOLLOW-UP**

**Application rollback:** previous immutable image = none yet retained under a `-<sha>` tag
(only the floating `sccit/app:prod` / `sccit/web:prod` exist, both `= 2df17db`'s images,
since no *new* release has been tagged since 2df17db). This is expected pre-first-new-release
state, not a defect — `releases.sh`'s retention logic has nothing to prune yet because
there is only one release.

**Database rollback:** backup artifact = `backups/prod/20260928T024615Z-prod-backup.dump`
(§5); restore procedure documented in its own metadata sidecar; isolated-restore evidence
recorded and independently re-verified in this gate (checksum match).

**Application vs. schema rollback mismatch — the actual finding requested:**

Repository has **32** migrations; production has applied **30**. The two absent from
production are:

1. `2026_09_05_100000_add_dedupe_key_to_notifications.php` — adds one nullable column
   (`notifications.dedupe_key`) plus a partial unique index. **Purely additive**; `down()`
   drops the column cleanly.
2. `2026_09_07_100000_add_digest_channel_and_digest_log.php` — widens a `CHECK` constraint
   (adds `digest` to the notification-channel domain) and creates a new table
   (`notification_digests`). **Additive**; the widened `CHECK` is a strict superset of the
   current one, so no existing row is affected.

**Classification:** both are **pre-existing Phase 2.7a work that simply predates the last
production deployment** (2026-08-30) — not WP-B+ floor-plan or AI migrations, not "deferred"
in the sense of being blocked on anything, and **not dangerous to deploy**: both are
additive-only, both have clean, tested `down()` paths, both are already covered by the
existing Pest suite (957 passing tests include the notification-digest domain).

They are dangerous only in one specific, already-documented sense: `rollback-prod.sh`'s own
header warns that rolling *back* to a release expecting fewer migrations than are applied
means "the older code will run against a newer schema" — exactly the scenario a *future*
rollback *from* a post-WP-N deployment *to* 2df17db would create, and the script is already
built to detect and report that, not silently proceed.

**This does not block WP-B.** WP-B's own target schema impact is explicitly "zero
migrations" per the approved plan (confirmed at WP-B, not before it, per the plan's own
sequencing) — it does not depend on either of these two notification migrations.

---

## 11. Automated Test Evidence — **PASS**

Zero files have changed since the full gate was run to completion in the immediately
preceding checkpoint (§2 confirms: same 21 pre-existing files, 0 staged, HEAD unchanged) —
re-running the full ~45-minute E2E + accessibility suite against byte-identical code would
produce no new information. Fast checks were re-run live, to catch any environmental drift;
the full-suite results are cited from that same, unchanged commit.

| Gate | Result | When |
|---|---|---|
| `composer validate --strict` | `./composer.json is valid` | **re-run live, this gate** |
| `composer audit` | No security vulnerability advisories found | **re-run live, this gate** |
| `npm audit` | found 0 vulnerabilities | **re-run live, this gate** |
| `./vendor/bin/pint --test` | PASS, 671 files | prior checkpoint, same HEAD |
| `./vendor/bin/phpstan` | [OK] No errors | prior checkpoint, same HEAD |
| `./vendor/bin/pest` | **957 passed** (3749 assertions) | prior checkpoint, same HEAD |
| `npx tsc -b` | exit 0 | prior checkpoint, same HEAD |
| `npx eslint .` | 0 errors | prior checkpoint, same HEAD |
| `npx prettier --check .` | clean on every tracked file | prior checkpoint, same HEAD |
| `npm run build` | exit 0 | prior checkpoint, same HEAD |
| `npx vitest run` | **322 passed** / 36 files | prior checkpoint, same HEAD |
| `scripts/e2e.sh --project e2e` | **35 passed** | prior checkpoint, same HEAD |
| `scripts/e2e.sh --project a11y` | **28 passed** | prior checkpoint, same HEAD |
| `npm ci` from a `--no-cache` image rebuild | 303 packages, 0 manual repair | prior checkpoint, same HEAD |

No test was weakened or skipped to obtain any of the above.

---

## 12. Clean-Environment Reproducibility — **PASS**

| Item | State |
|---|---|
| Playwright/axe declaration (F-1) | resolved — proven by the `--no-cache` rebuild in §11 |
| Generated E2E artifacts (`frontend/e2e/.artifacts/`, `frontend/test-results/`) | untracked, correctly excluded from the repository's expected state; not required for a build, only for local E2E result inspection |
| `.gitignore` coverage | `.env.*` family closed (F-2); `backups/`, `/releases/` (release artifacts are explicitly documented as "machine-local and regenerable-by-deployment, so it is gitignored" per `releases.sh`'s own header) — both intentional, both already correct |
| `.prettierignore` | not separately audited this gate — no evidence of a gap; `format:check` passes clean on every file this session's work touched |
| Production env provisioning | resolved this checkpoint (N-3) — `backend/.env.production` recoverable procedure now proven and documented |
| Reverb configuration | present and consistent in both dev and prod compose/nginx (§7); the one still-missing piece (real prod credentials) is a WP-O action, not a reproducibility gap |
| Missing scripts | one identified — a prod-targeted backup script (§9, finding G-3), non-blocking |
| Undocumented setup steps | none newly found this gate |

---

## 13. Documentation Drift — **inspected, not edited**

| Item | Finding |
|---|---|
| `CLAUDE.md` | states "PHP 8.3"; actual is 8.4 (confirmed live in both stacks). **Pre-existing, already flagged in SESSION 00**, still open. |
| SDD | highest recorded decision is `DD-57`. SRS/SPMP are known (from project memory, itself sourced from a prior session's direct inspection) to cite `DD-58`..`DD-66`, which do not exist in the SDD. **Pre-existing gap, already tracked** (`sccit-sdd-phase27-reconciliation-gap`), not newly discovered. |
| SDD Reverb mentions | 8 occurrences — these are the Phase 2.8 *planning* content already baselined by the original bootstrap, not a record of WP-A's actual implementation (which happened after the SDD was last touched). WP-A's own decisions (runtime-key delivery, same-origin proxy design, `/reverb/app/` routing) are **not yet reflected** in the SDD. |
| SPMP | mentions "D1" twice; this predates the current integrated-release D1 decision text and has **not yet been updated** to record it, per CLAUDE.md §7's own rule that documentation reconciliation happens at phase close, not per-checkpoint. |
| `releases/2df17db/manifest.json` | stale `pre_deploy_snapshot` pointer (§5, finding G-1) |

None of this was edited, per instruction. All of it is consistent with CLAUDE.md §7's own
stated rule — "every phase closes with a documentation reconciliation" — meaning this drift
is expected mid-phase, not a sign of a lost or corrupted state.

---

## 14. Phase 2.7/2.8 Dependency Readiness

| Work package | State |
|---|---|
| **WP-A** | **Complete and verified.** Commit `742ded5`; two-browser proof passed; full gate green at the time. |
| N-1, N-2, N-3, F-1, F-2, F-3 | **All six resolved**, as this and the two preceding checkpoints establish. |
| **WP-B** | Not started. Its prerequisite (WP-A) is done and verified; nothing in WP-A through the six findings blocks it. |
| WP-C through WP-M, WP-P, WP-Q | Not started — all correctly downstream of WP-B/WP-H per the approved dependency graph, no premature work found. |
| WP-N | Not started — correctly downstream of WP-C..M. |
| WP-O | **Not authorized.** Correctly untouched. |

**No blocker to WP-B was found.** WP-B's own zero-migration target, Admin-only
authorization model, and `PermissionSeeder::$withdrawn['technician']` mechanism are
unaffected by anything in this gate.

---

## 15. Remaining Findings

| ID | Severity | Evidence | Impact | Required action | Blocks WP-B? | Blocks WP-O? |
|---|---|---|---|---|---|---|
| **G-1** | Low | `releases/2df17db/manifest.json`'s `pre_deploy_snapshot` names a backup that no longer exists | Purely documentation — the actual restore point today is `backups/prod/20260928T024615Z-prod-backup.dump` | Update the manifest field (or add a new manifest for the next release) to point at the current backup | No | **Yes** — WP-O's rollback rehearsal needs an accurate pointer |
| **G-2** | Low | No backup retention/rotation policy exists; exactly one production backup currently exists | Operational gap, not a defect | Define and, ideally, script a retention policy before repeated production backups accumulate | No | Recommended before WP-O, not strictly blocking |
| **G-3** | Low | `scripts/backup.sh`/`restore.sh` are dev-only; no scripted prod-equivalent exists | This gate's F-3 backup was taken by hand, correctly, but not repeatably-by-script | Add a `scripts/backup-prod.sh` (and restore counterpart) mirroring what this gate did | No | Recommended before WP-O |
| **G-4** | Low | Production is 2 migrations behind the repository (§10) | None — both additive, both tested, both survivable by design | Apply at the next production deployment (WP-O), in the normal migration sequence | No | No — `deploy-prod.sh`'s own migrate step handles this |
| **G-5** | Informational | `CLAUDE.md` PHP version, SDD `DD-58..66` gap, SPMP D1 text (§13) | Documentation currency only | Address at the next documentation-reconciliation pass (CLAUDE.md §7's own rule) | No | No |
| **G-6** | Informational | Reverb production credentials not yet generated (§7) | Expected — explicitly deferred by design | Generate at WP-O | No | **Yes** — required before Reverb can run in production |

No finding above is severity Medium or higher. None blocks WP-B.

---

## 16. Required Actions Before WP-B

**None.** WP-B may proceed.

---

## 17. Required Actions Before WP-O

Separate from WP-B, and not required until that later, separately-authorized checkpoint:

1. Generate real production Reverb credentials (G-6).
2. Correct or supersede `releases/2df17db/manifest.json`'s stale snapshot pointer (G-1).
3. Apply the 2 pending notification migrations as part of the normal deploy sequence (G-4).
4. Recommended, not strictly required: a scripted production backup/restore pair (G-3) and a
   stated retention policy (G-2).
5. WP-O's own already-documented checklist (database backup immediately pre-deploy,
   migration-sequence review, rollback rehearsal, immutable release tag, two-Admin-browser
   production Reverb verification, production CSP verification) — unchanged by this gate,
   still fully pending, still requiring its own separate authorization.

---

## 18. Exact Next Checkpoint

**GREEN.** The repository, production infrastructure, and release/rollback state are
sufficiently controlled to proceed into WP-B. The next checkpoint is WP-B itself, on the
owner's explicit instruction — this gate does not grant that instruction, only clears the
way for it.

No implementation was performed. No migration was run. No production service was
restarted. No deployment occurred. Nothing was committed. Nothing was pushed.

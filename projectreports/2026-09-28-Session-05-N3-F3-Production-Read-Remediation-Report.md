---
title: "N-3 / F-3 PRODUCTION READ REMEDIATION REPORT"
project: "SccIT — School IT Service Management System"
work_package: "Owner-authorized production environment recovery and backup/restore verification"
stage: "COMPLETE — both N-3 and F-3 RESOLVED; production unmodified"
date: "2026-09-28"
author: "Engineering"
status: "N-3 RESOLVED · F-3 RESOLVED — all 6 production-readiness findings now closed"
baseline: "HEAD d2337bf (unchanged) · branch recovery/phase-2.7-restored-baseline (not pushed) · recovery baseline c9ab8a4"
---

# N-3 / F-3 PRODUCTION READ REMEDIATION REPORT

## 1. Authorization Boundary

**Authorized, explicitly, for this checkpoint only:** reading the live `sccit_prod_app`
container's own environment to reconstruct `backend/.env.production`; connecting to the
live `sccit_prod_postgres` database to inspect identity/version/migration state, create a
logical backup, and restore it into an isolated disposable target for verification.

**Remained prohibited, and none of it was done:** any production write, delete, schema
change, migration, user/ticket/QR/maintenance/configuration modification, production
container restart or recreation, deployment, WP-B, floor-plan work, any other Phase
2.7/2.8 feature, a push, and printing any secret value or production record content
anywhere in this transcript or report.

---

## 2. N-3

| Item | Value |
|---|---|
| Production container identified | `sccit_prod_app` |
| Verified against `compose.prod.yaml`'s `app_prod` service | image `sccit/app:prod`, `container_name: sccit_prod_app` — matches |
| Image ID | `sha256:de13d793201d…` — matches the running container **and** matches release `2df17db`'s recorded `app_id` in `releases/2df17db/manifest.json` exactly |
| Container running | yes, `RestartCount: 0` throughout this checkpoint |
| Method | a script run entirely *inside* the container (`docker exec`) wrote the reconstructed file to the container's own `/tmp`; the file was retrieved with `docker cp` (a binary file copy — never `cat`, never displayed). Only a line count (`59 lines written`) was ever printed to this transcript. |
| Keys recovered from the container's real, live values | 46 (every key in `backend/.env.production.example` except the 7 Reverb keys) |
| Keys copied verbatim from the tracked, reviewed `backend/.env.production.example` | 7 Reverb keys — `REVERB_APP_ID`/`KEY`/`SECRET` blank, `REVERB_HOST`/`PORT`/`SCHEME`/`ALLOWED_ORIGINS` the same non-secret operational defaults already committed in WP-A. **No production Reverb credential was invented.** Reverb has never been deployed to production; there is no live value to recover, and none was fabricated. A real credential set is a WP-O task. |
| `backend/.env.production` line count | 74 (59 recovered + 15 appended Reverb block) |
| Every expected key from `.env.production.example` present | yes — diffed key-by-key; 0 missing |
| Unexpectedly-empty values | none — the only blank lines are the 3 Reverb secrets, exactly as intended |
| `git check-ignore -q backend/.env.production` | **passed** — ignored |
| `git ls-files backend/.env.production` | **empty** — confirmed not tracked |
| `git status` | does not list it at all (correct behavior for a properly ignored file) |
| `docker compose -p sccit_prod -f compose.prod.yaml config` | resolved successfully; output was written to a file, inspected only for non-secret structural facts, then destroyed before this report was written (see §4) |
| `docker compose -p sccit_prod -f compose.prod.yaml config --quiet` | exit 0, no output — clean pass |
| Services resolved | all 8: `app_prod`, `mailpit_prod`, `nginx_prod`, `postgres_prod`, `queue_prod`, `redis_prod`, **`reverb_prod`**, `scheduler_prod` |
| Published host ports (only structural fact extracted from the resolved config) | **`8026`** (mailpit_prod UI) and **`8081`** (nginx_prod, the public entrypoint) — nothing else. `reverb_prod` correctly has none. | 
| Production container restarted/recreated | **no** — `RestartCount: 0` and `StartedAt` unchanged for `sccit_prod_app` before and after |
| **Status** | **RESOLVED** |

---

## 3. F-3

| Item | Value |
|---|---|
| Source database container | `sccit_prod_postgres` (`pgvector/pgvector:pg17`) |
| Database name | `school_it_service_management` (not a credential — confirmed safe to record per the instruction's own enumeration) |
| PostgreSQL version | **17.10** (Debian 17.10-1.pgdg12+1) |
| Extensions present | `vector 0.8.3`, `pg_trgm 1.6`, `citext 1.6`, `plpgsql 1.0` |
| Migration state at backup time | **30 Ran, 0 Pending** |
| Backup identifier | **`20260928T024615Z`** |
| Timestamp | 2026-09-28T02:46:15Z |
| Format | PostgreSQL custom archive (`pg_dump -Fc`) — chosen over the dev script's plain-SQL-gzip so the restore path below could use `pg_restore` directly, per PostgreSQL's own documented workflow for verified restoration |
| File | `backups/prod/20260928T024615Z-prod-backup.dump` — **406,799 bytes** |
| SHA-256 | `528aa15c4b4a66e5359c3c045cbb323e0c9698cb7c4d17545d60eaae1b9620c4` — verified identical between the container-side dump and the copy at rest |
| Archive structure (`pg_restore --list`, read-only, touches no database) | valid CUSTOM format, **1022 TOC entries**, correctly records source PG version 17.10 and the same 3 extensions |
| Secure storage location | `backups/prod/` — the project's own established convention (same directory `releases/2df17db/manifest.json` already references as its `pre_deploy_snapshot` path); confirmed git-ignored, both the dump and its metadata sidecar |
| **Restore target** | a **freshly created, fully isolated, disposable** container: `sccit_backup_verify` (same image, `pgvector/pgvector:pg17`), started with `--network none` — no route to production or any other network at all |
| Restore method | `pg_restore --no-owner --no-privileges` (production's role names don't exist in the throwaway container; ownership/privileges are irrelevant to a structural verification) |
| **Restore result** | **exit 0** — every foreign-key constraint in the schema was created successfully, which is only possible if every row's referential integrity is intact |
| Structural verification | extensions match source exactly (4/4); **82 tables**; `migrations` table row count = **30**, matching the live count exactly; all 7 spot-checked application tables present (`users`, `tickets`, `pc_units`, `maintenance_records`, `ai_embeddings`, `floor_plan_positions`, `room_layouts`) |
| Row-count cross-check (**counts only — no data content read or recorded**) | `users=2, roles=3, tickets=0, pc_units=0, maintenance_records=0, notifications=0` — **identical** between the restored copy and a fresh query against live production, confirming a consistent, read-only capture |
| Disposable verification container destroyed | **yes** — `docker rm -f sccit_backup_verify`; confirmed absent from `docker ps -a` afterward |
| Production database modified | **no** — row counts before and after are identical; no `INSERT`/`UPDATE`/`DELETE`/`ALTER` statement was ever issued against `sccit_prod_postgres` |
| Metadata recorded | `backups/prod/20260928T024615Z-prod-backup.meta.json` (gitignored) — identifiers, checksum, structural verification results, and the restore procedure; **no database contents, no credentials** |
| **Status** | **RESOLVED** |

---

## 4. Security Validation

| Check | Result |
|---|---|
| Any credential/secret value printed to this transcript | **No.** The only outputs from any command that touched real production configuration were: a line count (N-3), non-secret structural facts extracted from a since-destroyed resolved-config file (service names, port numbers), and PostgreSQL's own `pg_restore`/`pg_dump` progress lines, which name *tables*, never row values. |
| The one file that transiently held real secret values (`docker compose config`'s full resolved output, which — confirmed during this session — expands `env_file:` into inline `environment:` values) | Written to a temp file, inspected **only** via targeted greps for service/port structure, then deleted (`rm -f`) before any further command ran. Never opened with `cat`, `Read`, or any tool that would have surfaced it to me or the transcript. |
| Production record content (row values, not counts) | Never read, never recorded, never printed, at any point |
| Git tracking of secret-bearing artifacts | `backend/.env.production` and everything under `backups/prod/` confirmed git-ignored **before** and **after** every operation that touched them |
| Credentials rotated | None — not authorized, not needed (F-2's history scan already confirmed no prior exposure) |
| Temporary in-container files | The extraction script and its intermediate output were removed from `sccit_prod_app`'s `/tmp` (required `-u root`, since `docker cp` writes as root while the container's own user is `appuser`); the in-container copy of the dump was removed from `sccit_prod_postgres`'s `/tmp` after the host-side copy's checksum was confirmed |

---

## 5. Production Safety Validation

| Evidence | Result |
|---|---|
| `sccit_prod_app` `RestartCount` / `StartedAt` | `0` / unchanged throughout |
| `sccit_prod_postgres` `RestartCount` / `StartedAt` | `0` / unchanged throughout |
| `sccit_prod_queue` restarted during this checkpoint | Its `StartedAt` is recent, but this is its **documented, pre-existing, unrelated** behavior — `queue:work --max-time=3600` exits hourly by design and Docker's restart policy relaunches it (`RestartCount: 44`, a steady, expected count unrelated to anything in this session — recorded identically in the WP-A report from an earlier session) |
| Migration count/pending | 30 / 0 — unchanged before and after |
| Live row counts | Unchanged — cross-checked identically against the backup (§3) |
| No `docker compose ... up`, `restart`, or `recreate` was ever run against any `_prod` service | Confirmed by the restart-count evidence above |
| No production deployment | Confirmed — no image build, no `deploy-prod.sh` invocation |

---

## 6. Git Integrity

| Check | Result |
|---|---|
| HEAD | `d2337bf` — **unchanged** by this checkpoint (no new commit; see §8) |
| Remote (`origin/recovery/phase-2.7-restored-baseline`) | `742ded5` — unchanged; nothing pushed |
| 21 pre-existing tracked modifications | Fingerprinted at session start (`sha256: 79c8b2b8…c1a1a47a1c911dea7024`) and **re-verified identical** after every operation in this checkpoint — never staged, committed, reverted, cleaned, or overwritten |
| `backend/.env.production` | Not staged, not committed, not tracked (`git ls-files` empty) |
| `backups/prod/*` (dump + metadata) | Not staged, not committed, not tracked |
| Anything else staged | **0** files |
| Unrelated changes | None — this checkpoint touched only the two authorized artifacts, both outside git entirely |

---

## 7. Remaining Blockers

**None from N-1 through F-3.** All six findings from the original production-grade
remediation request are now resolved:

| Finding | Status |
|---|---|
| N-1 | RESOLVED (prior checkpoint) |
| N-2 | RESOLVED (prior checkpoint) |
| N-3 | **RESOLVED** (this checkpoint) |
| F-1 | RESOLVED (prior checkpoint) |
| F-2 | RESOLVED (prior checkpoint) |
| F-3 | **RESOLVED** (this checkpoint) |

**Genuine open items, none of them blockers on the six findings:**

- **Reverb production credentials do not yet exist.** By design (§2) — generating them is a
  WP-O task, not this checkpoint's.
- **Backup retention policy is not yet formalized.** One real, verified backup now exists;
  a recurring schedule and retention/rotation policy for `backups/prod/` is a reasonable
  next operational item but was outside this checkpoint's authorization.
- **This is the only backup that currently exists.** The historical
  `20260901T044951Z-gate-verify` snapshot referenced by `releases/2df17db/manifest.json`
  was correctly *not* recreated (it cannot be, honestly) — the manifest's `pre_deploy_snapshot`
  field now points to a path that predates this one. That field is stale documentation, not
  a functional problem; a future WP-O checkpoint should note the new backup as the current
  restore point.

---

## 8. Exact Next Checkpoint

**STOP**, as instructed. No commit was made this checkpoint — nothing produced here belongs
in git (both artifacts are correctly outside version control), so there was nothing to
commit. Not pushed. Not deployed. WP-B not started. No other Phase 2.7/2.8 work begun.

All six original findings (N-1, N-2, N-3, F-1, F-2, F-3) are now closed to the
production-grade standard requested. The next checkpoint is the owner's decision.

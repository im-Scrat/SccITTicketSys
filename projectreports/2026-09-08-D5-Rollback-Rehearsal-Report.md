---
title: "D5 ROLLBACK REHEARSAL REPORT"
project: "SccIT — School IT Service Management System"
work_package: "Phase 2.7 — production deployment readiness (D5)"
stage: "REHEARSAL COMPLETE — partial execution to the mutation boundary"
status: "No deployment · no migration · no container recreated · no data modified · nothing pushed"
date: "2026-09-08"
author: "Engineering"
baseline: "HEAD 6cd219e · production at migration 30 · loopback-only"
---

# D5 ROLLBACK REHEARSAL REPORT

## Classification

# YELLOW — ROLLBACK READY WITH LIMITATIONS

The rollback mechanism was **partially executed** — its resolution and planning
stages ran for real against live production state — and every artifact it depends
on was verified. What was **not** executed is the mutating half: the pre-rollback
snapshot, the tag move, and the container recreation.

**That limit was deliberate.** Stage 4 recreates `app_prod`, `nginx_prod`,
`queue_prod` and `scheduler_prod`, which modifies production state and causes
downtime. Your instruction was to stop and report rather than improvise, so I
did. **I am not calling this a fully executed rollback.**

---

## 1. Rollback target

| | |
|---|---|
| Release | `2df17db` |
| Commit | `2df17db3d1c761a7cdce0db8d4a0b31e4274e573` |
| Subject | *fix(assets): read meta.active unwrapped so the QR panel shows its label* |
| App image | `sccit/app:prod-2df17db` → `sha256:de13d793201d…` |
| Web image | `sccit/web:prod-2df17db` → `sha256:b8bd04e3c427…` |
| Expected migrations | 30 |

**Objective 8 — does it correspond to the known-good WP-2.6b state?** Yes, with a
precision worth stating: it is **WP-2.6b plus two accepted post-release fixes**.

```
3493660  feat(qr): WP-2.6b — QR-verified technician job workflow
  ec85b9a  fix(work-support): scope My submissions to technicians
  2df17db  fix(assets): read meta.active unwrapped so the QR panel shows its label
```

`3493660` is confirmed an ancestor of `2df17db`, and the tree at `2df17db`
carries exactly **30** migration files — matching production. So the target is
not merely "near" the known-good state; it **is** the state currently running.

---

## 2. Current release state

| | |
|---|---|
| `releases/current` | `2df17db` |
| Live `sccit/app:prod` | `sha256:de13d793201d…` |
| Live `sccit/web:prod` | `sha256:b8bd04e3c427…` |
| Production migrations | **30** |
| Containers | 7 running, 5 healthy + 2 workers |

Production is running the rollback target itself. That is expected before the
first Phase 2.7 deployment, and it shapes what a rehearsal can prove — see §12.

---

## 3. App / web image synchronization

**Verified synchronized.**

| Image | Built |
|---|---|
| `sccit/web:prod-2df17db` | `2026-08-30T04:13:54.339Z` |
| `sccit/app:prod-2df17db` | `2026-08-30T04:13:57.694Z` |

**3.35 seconds apart** — the same build run, not two independently produced
images. Both release tags resolve to exactly the same image IDs as the live
`:prod` pair:

```
app: MATCH        web: MATCH
```

They will move together: `rollback-prod.sh` retags both in adjacent statements,
so the pair cannot be split by the rollback path.

---

## 4. Manifest verification

`releases/2df17db/manifest.json` was parsed **with the scripts' own `json_field`
function**, not a different parser — so this proves the file is readable by the
tooling that will consume it, not merely that it is valid JSON.

| Field | Value |
|---|---|
| `schema` | `sccit.release/1` |
| `release` | `2df17db` |
| `deployed_at` | `2026-08-30T04:13:57Z` |
| `commit` | `2df17db3d1c761a7cdce0db8d4a0b31e4274e573` |
| `branch` | `chore/phase-0-tooling-recovery` |
| `app_tag` | `sccit/app:prod-2df17db` |
| `web_tag` | `sccit/web:prod-2df17db` |
| `migration_count` | `30` |
| `pre_deploy_snapshot` | `backups/prod/20260901T044951Z-gate-verify` |

Every field the rollback and release scripts read resolves correctly.

**The referenced snapshot was verified as a real restore point**, not just a
path: `gzip -t` reports **OK**, the dump begins with a valid PostgreSQL header,
and it contains the `migrations` table definition. `storage.tar.gz` and its own
`manifest.json` are present alongside it.

---

## 5. Rollback pointer verification

```
$ sh scripts/releases.sh list
RELEASE   DEPLOYED (UTC)        BRANCH                          MIGR  IMAGES
2df17db   2026-08-30T04:13:57Z  chore/phase-0-tooling-recovery  30    present <- deployed
```

`releases/current` = `2df17db`; the listing resolves the release, confirms both
images are **present**, reads the migration count from the manifest, and marks it
as deployed.

---

## 6. Rollback-script verification — **partially executed**

`sh scripts/rollback-prod.sh 2df17db` was **actually run**. Stages 1 and 2
executed against live production; the run then reached the confirmation gate and
stopped.

```
== 1/5  Verify release artifact
  OK    sccit/app:prod-2df17db present
  OK    sccit/web:prod-2df17db present
  OK    manifest: commit 2df17db3d1c761a7cdce0db8d4a0b31e4274e573, deployed 2026-08-30T04:13:57Z

== 2/5  Plan
  current release   2df17db
  rollback target   2df17db
  database          30 migrations applied (live)
                    30 expected by the target release

  Type "rollback" to proceed:   → declined
```

**What genuinely executed:** `docker image inspect` on both release tags; reading
and parsing the manifest; a live `SELECT count(*) FROM migrations` against the
production database; and the schema-ahead comparison (30 live vs 30 expected).

**Post-rehearsal state — nothing mutated:**

| Check | Before | After |
|---|---|---|
| `app:prod` | `de13d793201d…` | `de13d793201d…` |
| `web:prod` | `b8bd04e3c427…` | `b8bd04e3c427…` |
| Container uptime | 2 days / 50 min | **unchanged** |
| `backups/prod` entries | 2 | **2** |
| `prerollback` snapshots | 0 | **0** |
| Production migrations | 30 | **30** |

---

## 7. Deployment-script rollback verification

`deploy-prod.sh:83` reads `PREVIOUS="$(cat "$RELEASES/current" …)"`, which now
returns `2df17db`. On a failed deploy or smoke test it will print:

```
Roll back with:   sh scripts/rollback-prod.sh 2df17db
```

Each component confirmed resolvable: the manifest exists, and both
`sccit/app:prod-2df17db` and `sccit/web:prod-2df17db` inspect successfully. Before
D1 this printed **nothing at all**.

---

## 8. Database rollback implications

**No migration was reversed. `migrate:rollback` was not run. Production schema
untouched at 30.**

The two are strictly separate, and the script says so itself:

| | Application / image rollback | Database / schema rollback |
|---|---|---|
| Mechanism | Retag `:prod` → `prod-<sha>`, recreate containers | Restore a snapshot with `--restore-db` |
| Reversibility | Immediate, no rebuild, no network | Destructive — overwrites live data |
| Automatic? | Yes, in `rollback-prod.sh` | **No** — opt-in, separately confirmed |

**If migrations 31 → 32 are applied and the application must return to the
previous image**, the recovery strategy is:

1. **Preferred — code-only rollback, leave the schema forward.** Both migrations
   are **additive**: 31 adds a nullable `dedupe_key` plus a partial index; 32
   widens a CHECK domain and adds `notification_digests`. The 2df17db image
   ignores all three. `rollback-prod.sh` detects the mismatch and warns
   *"the live schema is AHEAD of the target release by 2 migration(s)"* rather
   than failing silently. **No data is lost.**
2. **Only if data must also return** — `rollback-prod.sh --restore-db <snapshot>`
   using the pre-deploy snapshot named in the failed release's manifest. This
   **is** destructive and overwrites the live database; it is confirmed
   separately from the code rollback.

**`migrate:rollback` is not part of either path.** Migrations are forward-only
in this project, and reversing them against production data would be a data-loss
operation rather than a safety net.

**This was not executed.**

---

## 9. Data-safety analysis

**The rollback procedure requires no destructive data deletion.**

| Concern | Finding |
|---|---|
| Does image rollback touch data? | **No** — it retags images and recreates containers |
| Does it drop columns or tables? | **No** |
| Does it delete rows? | **No** |
| Is a snapshot taken first? | **Yes** — stage 3 captures the current state *before* discarding it, so the evidence of what went wrong survives |
| If that snapshot fails? | Warns and continues — the code rollback is judged more urgent than the forensic copy |
| Is data restoration automatic? | **No** — `--restore-db` is explicit and separately confirmed |
| Additive-migration survivability | Old code ignores an extra nullable column and an unused table |
| Only genuinely destructive path | `--restore-db`, and `down()` on migration 32 (which deletes `digest` preference rows) — **neither is invoked by a rollback** |

---

## 10. Actual rehearsal performed

Stated precisely, per your instruction.

| Element | Characterisation |
|---|---|
| Release artifact verification (stage 1/5) | **ACTUALLY EXECUTED** |
| Manifest resolution and parsing | **ACTUALLY EXECUTED** — via the scripts' own parser |
| Live database migration-count query | **ACTUALLY EXECUTED** — against production |
| Schema-ahead comparison (stage 2/5) | **ACTUALLY EXECUTED** — 30 vs 30, equal, so the warning branch did not trigger |
| Confirmation gate | **ACTUALLY EXERCISED** — declined; script aborted before mutating |
| `releases.sh list` resolution | **ACTUALLY EXECUTED** |
| Deploy-script rollback-command generation | **STATICALLY VERIFIED** — `PREVIOUS` resolution read from source; components independently confirmed resolvable |
| Image-pair synchronization | **ACTUALLY EXECUTED** — `docker image inspect` on all four tags |
| Snapshot integrity | **ACTUALLY EXECUTED** — `gzip -t` OK, dump header and `migrations` table confirmed |
| Pre/post state comparison | **ACTUALLY EXECUTED** — proves nothing mutated |

**This is more than static inspection: the real script ran against real
production state and resolved real artifacts.** It is less than a full
rehearsal.

---

## 11. What was NOT executed

| Stage | Why not |
|---|---|
| **3/5 — pre-rollback snapshot** | Writes a new snapshot to `backups/prod/`; not needed and adds no proof |
| **4/5 — retag `:prod` and `--force-recreate`** | **Modifies production state and causes downtime.** Your instruction: stop and report rather than improvise |
| **5/5 — post-rollback smoke test** | Unreachable without stage 4 |
| `--restore-db` | Destructive; explicitly out of scope |
| `migrate:rollback` | Forbidden, and not part of the rollback path |
| The schema-ahead **warning branch** | Cannot trigger while live and target are both 30 |

---

## 12. Remaining limitations

**L1 — The mutating half has still never run in production.** WP-2.7d's **F-2**
remains open. This rehearsal narrows it — resolution, manifest parsing, artifact
presence and the confirmation gate are now proven — but the retag and container
recreation are not.

**L2 — A rollback today would be a functional no-op.** Production runs the same
image IDs the target resolves to, so retagging would change nothing observable.
Even a full execution would prove the *mechanism* moves tags and recreates
containers, not that it *restores a different application version*. **The first
genuinely meaningful rollback rehearsal is only possible after Phase 2.7 is
deployed**, when target and current finally differ.

**L3 — The schema-ahead warning is untested.** Its inputs are equal today (30 vs
30). It becomes live and meaningful at 32-vs-30 immediately after deployment.

**L4 — F-3 remains, though no longer as a gap.** The `rollback-preWP26b` images
are still unusable by `rollback-prod.sh` — they carry no release tag and no
manifest. That no longer matters, because `2df17db` now supersedes them as the
rollback target.

---

## 13. D5 GO / NO-GO

# YELLOW — ROLLBACK READY WITH LIMITATIONS

| Objective | Result |
|---|---|
| 1. `sccit/app:prod-2df17db` exists | ✔ verified |
| 2. `sccit/web:prod-2df17db` exists | ✔ verified |
| 3. Manifest exists and is valid | ✔ parsed with the scripts' own parser |
| 4. `releases/current` resolves | ✔ `2df17db` |
| 5. Rollback scripts resolve the previous release | ✔ **executed** — stages 1–2 |
| 6. Deploy script generates the rollback command | ✔ statically verified |
| 7. App/web a synchronized pair | ✔ 3.35s apart, IDs match live |
| 8. Target = known-good WP-2.6b state | ✔ WP-2.6b + 2 accepted fixes, 30 migrations |
| 9. No destructive DB rollback assumed | ✔ code and schema rollback strictly separate |
| 10. No destructive deletion required | ✔ image rollback touches no data |

**All ten objectives met.** YELLOW rather than GREEN for one honest reason: the
mutating stages were not executed, so I will not claim the rollback was proven
end to end. Calling this GREEN would overstate what was demonstrated.

---

## 14. Recommended next action

**Proceed to the Phase 2.7 loopback deployment.** Rollback readiness is as high
as it can be *before* a deployment exists to roll back from — and L2 is the
reason: the first meaningful full rehearsal requires target and current to
differ, which only happens once Phase 2.7 is live.

Concretely, I recommend the deployment authorization carry two additions:

1. **Treat the deploy's own failure path as the real F-2 test.** If the smoke
   test fails, execute `sh scripts/rollback-prod.sh 2df17db` for real — that is
   both the recovery and the rehearsal, under the exact conditions it exists for.
2. **After a successful deployment, consider a deliberate rehearsal** while
   `2df17db` is a genuinely different version: roll back, verify the schema-ahead
   warning appears (32 vs 30), confirm the older image runs against the newer
   schema, then roll forward. That would close **F-2** properly. It requires its
   own authorization and a maintenance window, and I am not proposing to do it
   unasked.

No deployment, no migration, no container recreation, no snapshot created, no
data modified, no SMTP configured, no DNS touched, no test data deleted, no code
changed, nothing pushed. Production remains at migration 30, loopback-only, with
the D1 rollback artifacts intact.

Stopping here. Awaiting your review and explicit authorization for the next
release-gate step.

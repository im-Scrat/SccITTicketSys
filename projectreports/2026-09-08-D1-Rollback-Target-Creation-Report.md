---
title: "D1 ROLLBACK TARGET CREATION REPORT"
project: "SccIT — School IT Service Management System"
work_package: "Phase 2.7 — production deployment readiness (D1 remediation)"
stage: "D1 COMPLETE — rollback target created and verified"
date: "2026-09-08"
author: "Engineering"
status: "No deployment · no migration · no container recreated · no production data touched · nothing pushed"
baseline: "HEAD 6cd219e · production at migration 30"
---

# D1 ROLLBACK TARGET CREATION REPORT

Finding **B1** is resolved. The current production release now has an immutable
release-tagged image pair and a manifest, so `rollback-prod.sh` has a real target
and `deploy-prod.sh` can print a concrete rollback command if the Phase 2.7
deployment fails.

---

## 1. Tags created

```
sccit/app:prod-2df17db
sccit/web:prod-2df17db
```

Both were created **from the resolved image IDs**, not from the floating `:prod`
tag, so they cannot drift if `:prod` is later moved.

---

## 2. Image IDs / digests

| Tag | Image ID |
|---|---|
| `sccit/app:prod-2df17db` | `sha256:de13d793201dfaa9a27ce33c36d0cfe628a42660c4955c5c5652ca06e497bf08` |
| `sccit/web:prod-2df17db` | `sha256:b8bd04e3c42739f947dc2f5fe43dafae10dd6080c725da53af40bb8c80a6705b` |

These are the same IDs `sccit/app:prod` and `sccit/web:prod` resolve to now, and
the same IDs already recorded in
`backups/prod/20260901T044951Z-gate-verify/manifest.json` — three independent
sources agreeing.

---

## 3. Manifest path

```
releases/2df17db/manifest.json
```

---

## 4. Manifest contents

No secrets are present in this file.

```json
{
  "schema": "sccit.release/1",
  "release": "2df17db",
  "deployed_at": "2026-08-30T04:13:57Z",
  "git": {
    "commit": "2df17db3d1c761a7cdce0db8d4a0b31e4274e573",
    "branch": "chore/phase-0-tooling-recovery",
    "dirty": false
  },
  "images": {
    "app_tag": "sccit/app:prod-2df17db",
    "web_tag": "sccit/web:prod-2df17db",
    "app_id": "sha256:de13d793201dfaa9a27ce33c36d0cfe628a42660c4955c5c5652ca06e497bf08",
    "web_id": "sha256:b8bd04e3c42739f947dc2f5fe43dafae10dd6080c725da53af40bb8c80a6705b"
  },
  "database": { "migration_count": "30" },
  "verification": { "gates": "not recorded", "smoke": "not recorded" },
  "previous_release": "",
  "pre_deploy_snapshot": "backups/prod/20260901T044951Z-gate-verify",
  "reconstructed": { … }
}
```

**Four fields are deliberately honest rather than convenient**, because this
manifest is reconstructed for a release that predates the tooling:

- `deployed_at` is the **true image build time** (`2026-08-30T04:13:57Z`), not
  today, so `releases.sh` orders history correctly.
- `verification.gates` and `verification.smoke` are **`"not recorded"`**, not
  `"passed"`. This release was deployed before the gates existed and I will not
  assert a verification that never ran.
- `pre_deploy_snapshot` points at the 2026-09-01 gate-verification snapshot. It
  is **not** a pre-deploy snapshot, but it *is* a valid restore point, because
  production has not changed since it was taken (still migration 30, same image
  IDs).
- A `reconstructed` block records who built this, why, that the commit is
  **inferred from build timestamps** (the images carry no commit label), and both
  caveats above — so nobody later reads this as a genuine deploy record.

---

## 5. Current-release pointer

```
releases/current  →  2df17db
```

`releases.sh list` confirms resolution:

```
RELEASE     DEPLOYED (UTC)          BRANCH                          MIGR  IMAGES
2df17db     2026-08-30T04:13:57Z    chore/phase-0-tooling-recovery  30    present <- deployed
```

Images **present**, correctly marked as the deployed release.

---

## 6. Rollback script verification

`rollback-prod.sh 2df17db` was run and **deliberately declined at the
confirmation prompt**. This is safe by construction: the script mutates nothing
until the literal string `rollback` is typed — steps 1 and 2 are resolve and
plan; the retag, the snapshot and the container recreation are all after the
prompt.

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

  Type "rollback" to proceed:   → declined, aborted
```

Both images resolve, the manifest parses, and the **migration-count comparison
works** — the mechanism that will warn when the live schema is ahead of a
rollback target. After the Phase 2.7 deployment that comparison will read
*32 live / 30 expected*, and the script will say so rather than fail silently.

*(Target and current are the same release today, which is correct — 2df17db is
what is deployed. It becomes the rollback target once Phase 2.7 is released.)*

**Post-attempt verification — nothing changed:** `:prod` still resolves to
`de13d793201d` / `b8bd04e3c427`; all 7 containers still show their original
uptime (2 days) with none recreated; **0** `prerollback` snapshots exist;
production still at 30 migrations.

---

## 7. Deploy script rollback-command verification

`deploy-prod.sh:83` reads `PREVIOUS="$(cat "$RELEASES/current" …)"`, which now
returns `2df17db` instead of empty. On a failed deploy or smoke test it will
print:

```
Roll back with:   sh scripts/rollback-prod.sh 2df17db
```

Each component of that command was confirmed to resolve:

| Component | Status |
|---|---|
| `releases/2df17db/manifest.json` | exists |
| `sccit/app:prod-2df17db` | resolvable |
| `sccit/web:prod-2df17db` | resolvable |

Previously this printed nothing at all, leaving an operator with a live,
unverified deployment and no scripted way back. **That gap is closed.**

---

## 8. Git working-tree status

```
HEAD              6cd219e   (unchanged)
commits created   0
tracked changes   none
```

`releases/` is gitignored (`.gitignore:42:/releases/`), so the new metadata does
not appear even as untracked — correct, since release records are machine-local
artifacts like `backups/`.

---

## 9. Confirmation of non-modification

| Asset | State |
|---|---|
| Containers | **Not recreated** — all 7 retain their prior uptime |
| Production database | **Untouched** — 30 migrations, no writes |
| Production data | **Untouched** — no row created, updated or deleted |
| Application code | **Unmodified** |
| Application configuration | **Unmodified** |
| `compose.prod.yaml` | **Unmodified** |
| Existing images | **Unmoved** — `:prod` still resolves to the original IDs; the new tags are additional names for the same images |
| Migrations | **None run** |
| Backups | **None created or deleted** |
| Git | **No commit, no push, no history change** |

The only changes are two additional Docker tags and two files under the
gitignored `releases/` directory.

---

## Status

**D1 is complete and verified. B1 is resolved.**

Still outstanding from the release gate, unchanged by this work: **D2** (how
authenticated smoke tests are performed), **D3** (loopback stack versus a
network-reachable host), **D4** (Mailpit is capture-only), and **D5** (whether to
rehearse the rollback to close F-2).

Stopping here as instructed. No deployment has begun.

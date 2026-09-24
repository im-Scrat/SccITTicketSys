# Operations Runbook

Deployment, backup, and rollback for the local production stack. Introduced by
**WP-2.7d** to replace the ad-hoc `build → up → migrate` sequence that WP-2.6b
exposed as unverifiable and unrecoverable.

> **Scope.** "Production" here means the **local production-parity stack** on
> `http://localhost:8081` (`compose.prod.yaml`). A network-reachable hosting
> environment is deliberately out of scope for Phase 2.7.

---

## 0. The rule that everything else depends on

The root `.env` sets `COMPOSE_PROJECT_NAME=sccit`, and by Compose's precedence
that **overrides** the `name:` written inside `compose.prod.yaml`. So a
production command that omits `-p sccit_prod` silently manages the *development*
stack instead.

Every script in `scripts/` sources [`scripts/lib/stack.sh`](../scripts/lib/stack.sh),
which carries those flags for you. **Prefer the scripts over raw `docker compose`.**

```sh
. scripts/lib/stack.sh
stack_select prod
dc ps                 # -> docker compose -p sccit_prod -f compose.prod.yaml ps
app_exec php artisan migrate --force
```

`make` is not installed on the current Windows host; the `make` targets are
conveniences that shell out to the same scripts. Run the scripts directly under
Git Bash.

---

## 1. Deploy

```sh
sh scripts/deploy-prod.sh                 # interactive; asks before it changes anything
sh scripts/deploy-prod.sh --yes           # unattended
sh scripts/deploy-prod.sh --skip-gates    # records "gates: skipped" in the manifest
```

Eight stages, in this order:

| # | Stage | Fails the deploy when |
|---|---|---|
| 1 | Preflight | the tracked working tree is dirty (a release tag names a commit, so an image built from a dirty tree cannot be rebuilt from that name — override with `--allow-dirty`) |
| 2 | Quality gates | any gate fails — **nothing has been touched yet** |
| 3 | Pre-deploy snapshot | — (skipped if the DB is down; `--skip-backup` leaves no restore point and says so) |
| 4 | Build + tag | the build fails — the running deployment is untouched |
| 5 | Deploy | — |
| 6 | Migrate | `migrate --force` fails |
| 7 | Smoke test | any HTTP check fails; prints the exact rollback command |
| 8 | Record release | — |

### Why the release tag matters

`compose.prod.yaml` pins `image: sccit/app:prod`. Building **overwrites that tag
in place**, so the moment a bad build finishes, the previous image has no name
and rollback is impossible. The deploy script therefore tags every build with
its commit first:

```
sccit/app:prod-<sha>     immutable, kept by the retention policy
sccit/web:prod-<sha>
sccit/app:prod           floating — what the stack actually runs
```

Rollback is then a retag: no rebuild, no registry, no network. Which is the
point — rollback has to work when the thing that broke *is* the build.

---

## 2. Backup and snapshots

```sh
sh scripts/backup.sh --stack prod                      # -> backups/prod/<ts>/
sh scripts/backup.sh --stack dev --label before-thing
sh scripts/backup.sh --stack prod --keep 20
```

A snapshot is a directory, not a loose dump:

```
backups/<stack>/<UTC-timestamp>[-label]/
├── database.sql.gz    pg_dump --clean --if-exists, gzipped
├── storage.tar.gz     uploads (prod: the sccit_prod_storage volume)
└── manifest.json      git commit, branch, image ids, migration count
```

**Why the manifest.** A dump alone cannot answer the question that matters
during a bad deploy: *which code was this database shaped for?* Restoring a
snapshot taken at migration 30 onto an image expecting 28 corrupts the
application quietly. `restore.sh` and `rollback-prod.sh` read the manifest and
warn when code and data disagree.

**Why uploads are included.** Repair evidence and work-support attachments
(WP-2.6/2.6b) live outside the database. A database-only backup restores rows
whose attachments have vanished — a half-rollback that looks successful.

Retention keeps the 10 most recent per stack (`--keep`, or `SCCIT_BACKUP_KEEP`).

`backups/` is gitignored: it holds real rows and real uploads.

### Restore

```sh
sh scripts/restore.sh --stack prod backups/prod/20260831T041500Z
sh scripts/restore.sh --stack dev  backups/dev/<ts>-label
sh scripts/restore.sh --stack dev  backups/old-flat-dump.sql.gz   # pre-WP-2.7d layout
```

**This destroys the target database.** It prints what it is about to overwrite —
stack, database, container, live row counts — and waits for you to type the
stack name. `--yes` skips the prompt and is what automation passes; a human
running this by hand should not use it.

It warns, rather than refuses, when the snapshot's migration count is *ahead* of
the running code — that is the dangerous direction, and the warning names it.

---

## 3. Rollback

```sh
sh scripts/rollback-prod.sh --list             # what is retained
sh scripts/rollback-prod.sh 2df17db            # code only
sh scripts/rollback-prod.sh 2df17db --restore-db backups/prod/<snapshot>
```

Five stages: verify the artifact still exists → show the plan → **snapshot the
current state first** → retag and recreate → smoke test.

`releases/current` is only updated **after** the smoke test passes. A rollback
that failed verification is not recorded as good.

### Rolling back code does not roll back the database

Migrations in this project are forward-only, and `migrate:rollback` against a
production dataset is a data-loss operation, not a safety net.

If the target release expects **fewer** migrations than are applied, the script
says so and continues: the older image runs against the newer schema and ignores
the columns and tables it does not know about. Every Phase 2.4–2.6b migration is
additive, so this is usually survivable — but it is a judgement call, and the
script makes you make it rather than making it for you.

To go back to the data as well, pass `--restore-db` with the pre-deploy snapshot
named in the failed release's manifest (`releases/<sha>/manifest.json` →
`pre_deploy_snapshot`).

### Retention

```sh
sh scripts/releases.sh list
sh scripts/releases.sh show <sha>
sh scripts/releases.sh prune --keep 5 --dry-run
```

Keeps the 5 most recent releases (`SCCIT_RELEASE_KEEP`). The **currently
deployed release and the one it would roll back to are never pruned**, whatever
`--keep` says — pruning the rollback target to save disk is how a retention
policy quietly becomes a single point of failure.

`releases/` is gitignored: the manifests name local Docker image ids, so they
describe this machine's images and cannot be shared. The retained *images* are
the real artifact.

---

## 4. Verification

```sh
sh scripts/smoke.sh --stack prod                          # HTTP, from the host
sh scripts/smoke.sh --stack prod --email a@b --password x # + authenticated round-trip
sh scripts/e2e.sh --project smoke                         # browser, against :8081
```

`smoke.sh` answers "is this deployment serving the application, or merely up?" —
containers report healthy long before that is true. It checks `/up`, `/api/health`
(database and Redis **from inside the app**), the SPA document, the hashed bundle
it references, the SPA fallback, four security headers, and that a bad login is
refused rather than 500.

The browser smoke adds the half curl cannot see: that the bundle actually boots,
logs no console errors, triggers no CSP violations, and that the CSP-hashed
inline theme script still runs. See [TESTING.md](TESTING.md).

Exit status is the number of failed checks, so callers can branch on it.

---

## 5. Quick reference

| Task | Command |
|---|---|
| Full quality gate | `sh scripts/gates.sh` |
| Deploy a release | `sh scripts/deploy-prod.sh` |
| List releases | `sh scripts/releases.sh list` |
| Roll back | `sh scripts/rollback-prod.sh <sha>` |
| Snapshot production | `sh scripts/backup.sh --stack prod` |
| Restore production | `sh scripts/restore.sh --stack prod <snapshot>` |
| Smoke-test production | `sh scripts/smoke.sh --stack prod` |
| Browser suite | `sh scripts/e2e.sh --project e2e` |

Related: [DOCKER.md](DOCKER.md) · [DEVELOPMENT.md](DEVELOPMENT.md) · [TESTING.md](TESTING.md)

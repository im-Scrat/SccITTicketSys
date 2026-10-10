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
production command that omits `-p sccit_prod` manages the wrong Compose project
(while the development stack existed, it clobbered that stack). The development
stack was retired on 2026-10-10; production is the only stack.

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
sh scripts/backup.sh --stack prod --verify                     # -> backups/prod/<ts>/, proven restorable
sh scripts/backup.sh --stack prod --label before-thing --verify
sh scripts/verify-backup.sh backups/prod/<ts>                  # prove an existing snapshot restores
sh scripts/backup.sh --stack prod --keep 20                    # opt-in: prune to the 20 newest
```

A snapshot is a directory, not a loose dump:

```
backups/prod/<UTC-timestamp>[-label]/
├── database.sql.gz    pg_dump --clean --if-exists, gzipped
├── storage.tar.gz     the public disk — sccit_prod_storage volume, mounted read-only
├── private.tar.gz     the private disk — ticket attachments, repair photos,
│                      work-support and asset files (snapshot format /2)
├── row-counts.tsv     exact per-table row counts, taken right after the dump
├── manifest.json      git commit/branch, image ids, migration, table and file counts, checksums
└── verification.txt   added by verify-backup.sh once the snapshot has restored
```

Taking a snapshot is read-only against production: `pg_dump` runs inside
`postgres_prod` over its local socket (no password is passed or printed) and the
upload volumes are mounted `:ro` into throwaway `--network none` containers.

**Private attachments.** Laravel's `local` disk (`storage/app/private`) holds
every uploaded attachment, served only through the controllers that authorize
each download — never by Nginx. Since 2026-10-11 it is the `sccit_prod_private`
volume, mounted on `app_prod`, `queue_prod`, `queue_ai_prod` and
`scheduler_prod` (not `nginx_prod`, not `reverb_prod`), so attachments survive
deploys and rollbacks. Before that it was each container's own layer, lost on any
recreate; no file had been uploaded, so the rollout needed no backfill.
`backup.sh` archives the volume read-only (`"private_source": "volume"`). Should
`app_prod` ever run without that mount, it reads the files from inside the
container instead (`"private_source": "container"`) rather than miss them — the
same path that captured the pre-rollout state.

**Proving a snapshot.** `verify-backup.sh` (also run by `--verify`) restores the
snapshot into a disposable PostgreSQL — the production image, `--network none`,
tmpfs, removed afterwards — twice: into an empty database, then `--clean` over
the copy, which is the path a live restore takes. Both passes use `restore.sh`'s
exact `psql` command. It then compares the table count, migrations and every
table's exact row count with the snapshot's record, and extracts both upload
archives, counting their files. Production is never contacted. Snapshots of the
earlier format `/1` (no `private.tar.gz`) still verify, with a warning. Until that passes, a snapshot is *written*, not
*valid*.

**Output contract.** `backup.sh` prints exactly one stdout line — the snapshot
path — and only on success; `deploy-prod.sh` stops a deployment when it is
empty. The pre-WP-2.7d script this replaced (restored by mistake during the
2026-09 recovery) dumped the development database whatever `--stack` said and
reported success even when `pg_dump` failed.

**Why the manifest.** A dump alone cannot answer the question that matters
during a bad deploy: *which code was this database shaped for?* Restoring a
snapshot taken at migration 30 onto an image expecting 28 corrupts the
application quietly. `restore.sh` and `rollback-prod.sh` read the manifest and
warn when code and data disagree.

**Why uploads are included.** Repair evidence and work-support attachments
(WP-2.6/2.6b) live outside the database. A database-only backup restores rows
whose attachments have vanished — a half-rollback that looks successful.

Retention is opt-in: nothing is deleted unless you pass `--keep N`, which only
considers well-formed snapshot directories and never prunes the pre-deploy
snapshot of the release in `releases/current`.

`backups/` is gitignored: it holds real rows (password hashes included) and real
uploads. On this Windows host the folder inherits a `BUILTIN\Users` (modify) ACL
from the project directory — tighten it if other local accounts exist.

Older loose dumps (`backups/*.sql.gz`, and the 2026-09-28 `backups/prod/*.dump`
with its `.meta.json`) predate this layout and are kept as they are.
`restore.sh` refuses them: they carry no manifest, so nothing proves which
database or code they belong to.

### Restore

```sh
sh scripts/restore.sh --stack prod --dry-run backups/prod/<ts>    # every check, no change
sh scripts/restore.sh --stack prod backups/prod/<ts>              # interactive: type "prod"
sh scripts/restore.sh --stack prod --db-only backups/prod/<ts>    # leave both upload volumes as they are
```

**This overwrites the production database** (and both upload volumes, unless
`--db-only`). Private attachments are only restored into the
`sccit_prod_private` volume: if `app_prod` ever runs without that mount, restore
refuses rather than write them into a container layer where they would vanish
at the next recreate (`--db-only` still works). A format `/1` snapshot leaves
private files as they are. Before anything
changes it checks the manifest, checksums, gzip
streams and dump footer; restores the snapshot in a disposable database first;
shows live vs snapshot migrations and row counts — warning when the snapshot is
*ahead* of the running code, the dangerous direction — and then waits for you to
type `prod`. `--yes` skips the prompt and is what automation
(`rollback-prod.sh`) passes; without a terminal and without `--yes` it refuses.
`--dry-run` stops before the prompt, having changed nothing.

Only then does it take a **verified safety snapshot** of the current state
(aborting if that fails), stop `queue_prod`, `queue_ai_prod` and
`scheduler_prod`, put `app_prod` into maintenance mode (the maintenance flag is
file-based and per-container here), restore the database in **one transaction**
— any error rolls the whole restore back — swap the uploads in, and bring
everything back, also when a step fails. It prints the command that undoes it.

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
```

The browser smoke (`scripts/e2e.sh --project smoke`) ran Playwright inside the
development stack's `node` container and was retired with it on 2026-10-10;
the Playwright suites now run only in CI's opt-in browser job.

`smoke.sh` answers "is this deployment serving the application, or merely up?" —
containers report healthy long before that is true. It checks `/up`, `/api/health`
(database and Redis **from inside the app**), the SPA document, the hashed bundle
it references, the SPA fallback, four security headers, and that a bad login is
refused rather than 500.

The (now CI-only) browser smoke covered the half curl cannot see: that the
bundle actually boots, logs no console errors, triggers no CSP violations, and
that the CSP-hashed inline theme script still runs. See [TESTING.md](TESTING.md).
`smoke.sh` makes one deliberately failed login (`smoke-no-such-user@invalid.test`)
to prove the API refuses it rather than erroring.

Exit status is the number of failed checks, so callers can branch on it.

---

## 5. Quick reference

| Task | Command |
|---|---|
| Full quality gate | `sh scripts/gates.sh` |
| Deploy a release | `sh scripts/deploy-prod.sh` |
| List releases | `sh scripts/releases.sh list` |
| Roll back | `sh scripts/rollback-prod.sh <sha>` |
| Snapshot production | `sh scripts/backup.sh --stack prod --verify` |
| Prove a snapshot restores | `sh scripts/verify-backup.sh backups/prod/<ts>` |
| Restore production | `sh scripts/restore.sh --stack prod [--dry-run] <snapshot>` |
| Smoke-test production | `sh scripts/smoke.sh --stack prod` |
| Browser suite | CI only — the opt-in `workflow_dispatch` job in `.github/workflows/ci.yml` |

Related: [PRODUCTION_DEVELOPER_FILE_MAP.md](PRODUCTION_DEVELOPER_FILE_MAP.md) · [DOCKER.md](DOCKER.md) · [DEVELOPMENT.md](DEVELOPMENT.md) · [TESTING.md](TESTING.md)

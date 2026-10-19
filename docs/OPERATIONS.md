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

**`releases/` is not gitignored.** `releases/current` and
`releases/2df17db/manifest.json` are tracked; later manifests (such as
`releases/1b37ae9/manifest.json`) are not. A manifest names this machine's
Docker image ids and local snapshot path, so it describes this machine rather
than anything another checkout can use — the retained *images* are the real
artifact. Review each deployment record individually and commit it only as a
deliberate decision, never in passing with other work, and never with secrets or
other sensitive data in it — the same rule as
[PRODUCTION_DEVELOPER_FILE_MAP.md](PRODUCTION_DEVELOPER_FILE_MAP.md) §0.

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
| Change a dependency (lock files) | `sh scripts/lock-update.sh composer require …` / `npm install …`, then the gate ([DEVELOPMENT.md](DEVELOPMENT.md#dependencies)) |
| Deploy a release | `sh scripts/deploy-prod.sh` |
| List releases | `sh scripts/releases.sh list` |
| Roll back | `sh scripts/rollback-prod.sh <sha>` |
| Snapshot production | `sh scripts/backup.sh --stack prod --verify` |
| Prove a snapshot restores | `sh scripts/verify-backup.sh backups/prod/<ts>` |
| Restore production | `sh scripts/restore.sh --stack prod [--dry-run] <snapshot>` |
| Smoke-test production | `sh scripts/smoke.sh --stack prod` |
| Browser suite | CI only — the opt-in `workflow_dispatch` job in `.github/workflows/ci.yml` |

Related: [PRODUCTION_DEVELOPER_FILE_MAP.md](PRODUCTION_DEVELOPER_FILE_MAP.md) · [DOCKER.md](DOCKER.md) · [DEVELOPMENT.md](DEVELOPMENT.md) · [TESTING.md](TESTING.md)

## 6. Report documents and email (Phase 2.10)

**Where they are made.** Report exports (PDF, Word, Excel, CSV) and emailed
reports render on `queue_bulk_prod`, the worker that works only the `bulk`
queue. If exports sit at "Preparing" for more than a minute, check that worker
first: `docker compose -p sccit_prod -f compose.prod.yaml ps queue_bulk_prod`
and its logs. Deploy, rollback and restore all handle it like the other
workers.

**Where the files go.** `storage/app/private/report-exports/<uuid>/` on the
`sccit_prod_private` volume, so they are in every snapshot while they exist.
`reports:prune-exports` runs daily at 03:40 Asia/Manila: files older than
72 hours are deleted (their rows stay, marked expired), email copies still
queued after 24 hours are marked failed, and request IPs and user agents on
rows older than 180 days are cleared.

**Email.** Local production SMTP points at the Mailpit sink (`:8026`) — see §8 for the SMTP2GO configuration — so emailed
reports are captured there and reach no real mailbox — the dialog says so while
`REPORT_EMAIL_DELIVERS_EXTERNALLY` is false. **Mailpit keeps a copy of every
attachment** until its own data is cleared; treat its volume as holding report
data. Before real delivery: configure the school's SMTP in `.env.production`,
set `REPORT_EMAIL_DELIVERS_EXTERNALLY=true` and, if the school's addresses are
on a domain other than the sending one, `REPORT_EMAIL_INTERNAL_DOMAINS`
(comma-separated). Every other report setting has a default in
`backend/config/reports.php`.

**AI interpretations of reports (Phase 2.10 R5)** are **off** until an
administrator ticks "AI interpretations of reports" in AI settings. With it on,
asking for one sends aggregated figures with equipment and room codes (no
names, ticket text, notes or costs) to the configured model, on the `ai` queue,
at most 12 requests an hour per administrator. A period without enough
recorded data never calls the provider — the page says "Not enough data".

**Asset import (Phase 2.10 R6).** Administrators import PC units and other
equipment from `/app/assets/import`. Every step after the upload runs on the
`bulk` queue (`queue_bulk_prod`); `assets:imports-maintain` runs every 15
minutes to resume a save whose worker was killed (for example by a deploy),
expire unconfirmed drafts after 7 days, and delete uploaded files — with the
raw cells — 30 days after an import finished. An import never updates an
existing record; duplicates are skipped and listed.

*Production acceptance of an import* is done in two steps: (1) a dry run —
upload, map, review, confirm screen, then **Cancel** (nothing is saved, and
the file is deleted); (2) the first real save only with the school's own
inventory file, scheduled with the owner, immediately after
`sh scripts/backup.sh --stack prod --verify`. Never import test data into
production.

## 7. Credentials and secret handling (2026-10-20)

**This repository is public.** Anything committed is published, including
history. Until 2026-10-20 production ran on the development passwords that the
examples document, and `compose.prod.yaml` silently fell back to them when a
variable was unset. Three things now prevent a repeat:

- `compose.prod.yaml` has **no password fallbacks** — an unset
  `POSTGRES_PASSWORD` or `REDIS_PASSWORD` stops the stack with a message.
- `deploy-prod.sh` **refuses to deploy** while either password is empty, a
  placeholder, shorter than 16 characters or a published development value, or
  while the root `.env` and `backend/.env.production` disagree. It compares
  values; it never prints them.
- CI runs **gitleaks** over the full history on every push and pull request
  (`.gitleaks.toml` allowlists only the redaction tests' fake fixtures).

### Rotating the database and Redis passwords

```bash
sh scripts/rotate-prod-credentials.sh
```

It takes a verified snapshot, keeps the current env files in
`backups/credentials/<timestamp>/` (gitignored, local), generates two
40-character passwords without displaying them, runs `ALTER ROLE` through the
database container's local socket, updates both env files, recreates
`redis_prod` and every service running the app image, then verifies that
Laravel reaches PostgreSQL and Redis and runs the smoke test. On failure it
prints the exact commands to put the previous files back. No data changes:
the role keeps its name and privileges, and Redis keeps its append-only file.

Delete `backups/credentials/<timestamp>/` once satisfied — it holds the old
values. Never copy it, or any `.env` file, into the repository.

### Secrets in logs and failed jobs

Production PHP sets `zend.exception_ignore_args = On`, so stack traces carry no
argument values. On top of that, every log channel runs
`App\Support\Logging\RedactSecretsProcessor`, and `failed_jobs.exception` is
written through `App\Support\Queue\RedactingFailedJobProvider`: both replace
the values listed in `App\Support\Security\ConfiguredSecrets` (app key, DB and
Redis passwords, SMTP username and password, Reverb and S3 secrets, AI keys)
with `[redacted]`. `/api/health` reports only `ok`/`error`/`not_used` per
dependency — the reason goes to the log, never to the anonymous caller.

### Seeders never reset live data

`AdminUserSeeder` creates the first administrator only when that address does
not exist yet, and `SystemSettingSeeder` writes a setting's value only when the
setting is new. A re-run of `db:seed --force` therefore cannot reset the
administrator's password or undo a changed setting.

## 8. Outgoing mail and SMTP2GO (2026-10-20)

**Local production keeps Mailpit** (`mailpit_prod`, UI on `:8026`): nothing it
captures reaches a real mailbox. The application is ready for a real relay
through environment variables only — no code differs between Mailpit and
SMTP2GO:

| Variable | Mailpit (local) | SMTP2GO (pilot / later production) |
|---|---|---|
| `MAIL_HOST` / `MAIL_PORT` | `mailpit_prod` / `1025` | `mail.smtp2go.com` / `2525` (Render's free tier blocks 25, 465, 587) |
| `MAIL_SCHEME` / `MAIL_REQUIRE_TLS` | unset / unset | `smtp` / `true` — STARTTLS mandatory, credentials never in clear |
| `MAIL_TIMEOUT` | unset (60 s) | `10` |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | unset | an SMTP2GO SMTP user (secret) |
| `MAIL_FROM_ADDRESS` | `noreply@sccit.local` | a sender verified in SMTP2GO |
| `MAIL_TEST_REDIRECT_TO` | never | never — sandbox only, and ignored when `APP_ENV=production` |

**Checking a relay without sending.** `php artisan mail:check` prints the
configuration (credentials only as set / not set), connects, negotiates TLS and
authenticates, and disconnects without sending. `--send=<address>` sends one
fixed test message. Where there is no shell (the Render pilot), the
Administrator page **Outgoing email** does the same; its test message can only
go to the signed-in administrator.

**Testing SMTP2GO from this laptop** — `scripts/mail-sandbox.sh check` /
`send <address>` runs `mail:check` in a one-off container with only the
gitignored `backend/.env.smtp2go.local`, `APP_ENV=local` and a mandatory
`MAIL_TEST_REDIRECT_TO`. It never touches the production stack, its database
or its users. The free SMTP2GO plan allows 25 messages an hour until a sender
domain is verified.

**What "sent" means.** All mail is queued (account emails too, after their
transaction commits) and retried after 1, 5 and 15 minutes. A report delivery
row says `sent` when the relay **accepted** the message, or `failed` with
`smtp_auth_failed`, `smtp_rejected`, `smtp_deferred`, `tls_unavailable`,
`connection_failed` or `transport_error`. Delivered, bounced and spam-filed are
visible only in SMTP2GO's Activity log.

**Before real users receive mail** (not yet approved): a sender *domain*
verified in SMTP2GO (its three CNAME records plus a DMARC record), the
placeholder user addresses corrected (two are on registrable domains), and
`REPORT_EMAIL_DELIVERS_EXTERNALLY=true` set deliberately.

**Schedules restored 2026-10-20.** `notifications:send-digest` runs hourly from
07:00 to 10:00 Asia/Manila (at most one digest per user per day) and
`tickets:detect-sla` hourly. Both were scheduled in Phase 2.7 and lost in the
2026-09 workspace rebuild.

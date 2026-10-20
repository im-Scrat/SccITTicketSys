# Render free-tier pilot

A hosted **pilot** of SccIT on Render's free tier, with **synthetic data only**.
It exists to evaluate the application online, independent of the laptop. It is
**not** the production system and must not hold real users, tickets or files.

The real production system is the Docker stack in `compose.prod.yaml`
(`http://localhost:8081`). It stays authoritative until a separate, approved
migration. Nothing in this pilot reads or writes its data.

## What the free tier can and cannot do

Verified against Render's documentation (render.com/docs/free) on 2026-10-20:

| Limit | Consequence for the pilot |
|---|---|
| Render says of free instances: "Do not use them for production applications" | Pilot only. Commercial use needs paid plans. |
| 512 MB RAM, 0.1 CPU | One container; report documents capped (`REPORT_PAGED_ROW_BUDGET=200`, `REPORT_XLSX_CELL_BUDGET=50000`); pages are slower. |
| Spins down after 15 min without traffic; ~1 min to wake | A GitHub Actions keep-alive covers 06:00–20:00 Manila only. Outside it, the first request waits about a minute. |
| 750 instance-hours per workspace per month, then suspension | The keep-alive window uses about 434 h. Run one free web service only. |
| No shell, no pre-deploy command, no persistent disk | Migrations run at container start (`RUN_MIGRATIONS=1`). Uploads go to object storage. Mail checks use the admin page **Outgoing email**. |
| Container filesystem lost on restart, redeploy or spin-down | Nothing durable is kept in the container. |
| Free web services can't receive private-network traffic | nginx, PHP-FPM, Reverb, the queue worker and the scheduler run in one container under supervisord. |
| Outbound SMTP blocked on ports 25, 465 and 587 | SMTP2GO on port **2525**, with STARTTLS required. |
| Free Postgres expires after 30 days and has no backups; free Key Value is not persistent | External Postgres (Supabase free); cache, queue and sessions in Postgres; no Redis. |
| Without a payment method, overages suspend the service rather than bill | **Do not add a payment method** if the cost must stay at $0. |
| `onrender.com` is on the Public Suffix List | SPA and API share one origin, so Sanctum's cookies work. |

External services, verified the same day:

- **Supabase free:** 500 MB database, 1 GB storage, **no backups, no point-in-time recovery**.
  - The project pauses after a week without enough database activity, and can be restored for a year after pausing.
  - Direct connections are IPv6-only, so connect through the **session pooler** (IPv4).
- **SMTP2GO free:** 1,000 emails a month and 200 a day.
  - Until a sender **domain** is verified, the limit is **25 an hour**.
  - Mail beyond the monthly cap is **rejected, not queued**.
  - The free plan is "primarily for trial purposes".
- **Gemini free tier:** "Do not submit sensitive, confidential, or personal information." AI stays **off** on the pilot unless you approve otherwise.

## Architecture

```
Browser ─https─▶ Render edge (TLS) ─▶ container :$PORT
   nginx ─ SPA (static)
         ├ /api /sanctum /up /broadcasting ─▶ php-fpm 127.0.0.1:9000
         └ /reverb/app ─▶ Reverb 127.0.0.1:6001
   queue:work (all queues, database driver) · schedule:work      [supervisord]
        ├─▶ Supabase Postgres (session pooler, TLS)
        ├─▶ Supabase Storage (S3 API) — attachments, imports, report files
        └─▶ SMTP2GO mail.smtp2go.com:2525 (STARTTLS required)
GitHub Actions: CI · gitleaks · keep-alive · nightly encrypted backup · manual deploy
```

The files involved:

- `docker/render/Dockerfile`, with `nginx.conf.template`, `supervisord.conf`, `entrypoint.sh`, `php.render.ini` and `www.render.conf`.
- `render.yaml`.
- `.github/workflows/{deploy-render,backup-pilot,keepalive-pilot}.yml`.
- `scripts/pilot-restore-drill.sh`.

## What you set up (manual steps)

Create nothing paid. Each step happens once.

1. **SMTP2GO.**
   - Under Sending → Verified Senders, verify a **single sender** you control. Avoid Yahoo- or AOL-style free webmail: their DMARC policies cause rejections.
   - Create SMTP user `sccit-render` (and `sccit-sandbox` for local tests).
   - Turn on quota alerts.
2. **Supabase.**
   - Create a project in the region nearest Singapore.
   - In the SQL editor, run `select extname from pg_available_extensions where extname in ('vector','citext','pg_trgm');`. All three must be listed.
   - Create a **private** bucket and S3 access keys (Storage → Settings → S3).
   - Copy the **session pooler** connection details.
3. **Render.**
   - Create a Hobby workspace with **no payment method**.
   - New → Blueprint → this repository, branch **`main`**.
   - Fill in every `sync: false` value (see the table below).
   - Then, in the Blueprint's settings, set **Auto Sync → No**.
   - In the service, copy the **Deploy Hook** URL.
4. **GitHub** (repository Settings):
   - **Environment `render-pilot`:** you as required reviewer; secret `RENDER_DEPLOY_HOOK`.
   - **Environment `pilot-backup`** (no reviewer, so nightly runs proceed):
     - Secrets `PILOT_DB_URL` (session pooler URL), `PILOT_S3_ACCESS_KEY_ID`, `PILOT_S3_SECRET_ACCESS_KEY`, `BACKUP_AGE_RECIPIENT`, `BACKUP_REPO`, `BACKUP_REPO_TOKEN`.
     - Variables `PILOT_S3_BUCKET`, `PILOT_S3_ENDPOINT`, `PILOT_S3_REGION`.
   - **Repository variables:** `PILOT_URL`, then `PILOT_BACKUP_ENABLED=true` and `KEEPALIVE_ENABLED=true` once the first deploy works.
5. **Backups.**
   - Create an age key pair **offline**: `age-keygen -o pilot-backup.key`.
   - The public key (`age1…`) goes into `BACKUP_AGE_RECIPIENT`. The private key file **never** goes to GitHub.
   - Create an empty **private** repository for the ciphertext. `BACKUP_REPO_TOKEN` is a fine-grained token with *Contents: read and write* on that repository only.

### Render environment values you enter

| Key | Value |
|---|---|
| `APP_KEY` | output of `php artisan key:generate --show` (new; never production's) |
| `APP_URL`, `FRONTEND_URL` | `https://<service>.onrender.com` |
| `SANCTUM_STATEFUL_DOMAINS`, `REVERB_ALLOWED_ORIGINS`, `MAIL_EHLO_DOMAIN` | `<service>.onrender.com` |
| `DB_HOST`, `DB_USERNAME`, `DB_PASSWORD` | Supabase session pooler host, `postgres.<project-ref>`, database password |
| `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT` | Supabase S3 keys, region, bucket, `https://<project-ref>.storage.supabase.co/storage/v1/s3` |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | new random values (e.g. `openssl rand -hex 16`) |
| `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | SMTP2GO user `sccit-render`, its password, the verified sender |
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | the pilot's first administrator: an address you control, a new password |

Branch `main` · Dockerfile `./docker/render/Dockerfile` · context `.` · health check `/up` · auto-deploy **off**.

**Never set** `MAIL_URL`, `MAIL_TEST_REDIRECT_TO` or any `REDIS_*` value. Never reuse a value from local production.

**A service created by hand ("New Web Service") instead of from `render.yaml`** gets none of the Blueprint's fixed values. Copy every `value:` entry in `render.yaml` into the dashboard as well — in particular `APP_ENV=production`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database` and `AI_GEMINI_CONCURRENCY=0`.

### Enabling AI on the pilot

AI is off unless you choose otherwise (see the Gemini free-tier warning above: synthetic data only). To turn it on:

1. **Environment → Secret Files → Add**: file name exactly `gemini_api_key`, contents the Gemini API key. Render mounts it at `/etc/secrets/gemini_api_key`.
2. **Environment variables**: `GEMINI_API_KEY_FILE=/etc/secrets/gemini_api_key` and `AI_GEMINI_CONCURRENCY=0`. The pilot has no Redis: without the `0`, every AI call tried the Redis concurrency funnel and failed. Since 2026-10-20 (SDD DD-116) the application falls back to the per-minute limit and logs a warning instead, but set the `0` anyway. In production `GEMINI_API_KEY` itself is ignored; only the file is read.
3. **Deploy** (Manual Deploy → *Deploy latest commit*, or *Restart service*). The configuration is cached when the container starts, so saving the variables alone changes nothing.
4. Sign in as the administrator → **AI settings**. The page should not say "No key configured" or "no chat model selected". Press **Test the connection**: it makes one chat call and one embedding call and reports what Gemini answered (an invalid key, a model the key cannot use, or a free-tier quota).
5. The assistant button then appears for every role. It is hidden, not broken, while AI is unavailable, so its absence means steps 1–3 are not complete.

## Deploying

The only route is **Actions → Deploy — Render pilot → Run workflow**, with a commit on `main`. The workflow:

1. waits for your approval in the `render-pilot` environment;
2. checks that the commit is on `main` and that its `Backend (Laravel)`, `Frontend (React)` and `Secret scan (gitleaks)` checks all passed;
3. takes a fresh encrypted backup (`backup-pilot.yml`);
4. calls the deploy hook with `?ref=<sha>`;
5. waits for the instance to come up, then runs `scripts/smoke.sh --url`.

**First deploy:** set `RUN_FIRST_INSTALL=1` in Render before it, then back to `0` after it. The entrypoint seeds only when the database has no users at all, so a forgotten `1` is refused, not re-applied.

## Verifying a deploy

1. `sh scripts/smoke.sh --url https://<service>.onrender.com`. It must pass; `redis` reports *not used*.
2. Sign in as the first administrator. Open **Outgoing email**. It should show `mail.smtp2go.com:2525`, *Encryption required*, *Credentials present*, and no test redirect.
3. **Test the connection.** It must report *Connected, encrypted and signed in. No message was sent.*
4. **Send a test email to me.** It arrives at the administrator's address; SMTP2GO Activity shows *delivered*.
5. Create a synthetic teacher (an address you control) and request a password reset. The link must open `https://<service>.onrender.com/reset-password…`.
6. Upload a ticket attachment and download it. The file must be in the bucket, not on the container.
7. Open a floor plan in two browsers. A status change appears live; if the socket drops, polling takes over.
8. Check the service logs for credentials: there must be none. `failed_jobs` must stay empty.

## Rolling back

- **Code:** run the deploy workflow with the previous good commit. Render also keeps earlier deploys in its dashboard.
- **Data:** the pilot holds synthetic data only.
  1. Restore the latest encrypted backup on your own machine into a new database with `scripts/pilot-restore-drill.sh <file.tar.age> pilot-backup.key`. It proves the backup restores.
  2. For a real recovery, `pg_restore` the same dump into a new Supabase project.
  3. Repoint `DB_*`.
- **Local production is never touched** by any of this.

## Moving real data here

Not in scope, and not approved. It needs:

- a budget for paid hosting with backups;
- a verified sender domain;
- corrected placeholder user addresses;
- a decision on AI and personal data.

See the production-readiness plan, phase P9.

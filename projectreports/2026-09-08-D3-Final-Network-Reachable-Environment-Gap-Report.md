---
title: "D3 FINAL NETWORK-REACHABLE ENVIRONMENT GAP REPORT"
project: "SccIT — School IT Service Management System"
work_package: "Phase 2.7 — production deployment readiness (D3 gap analysis)"
stage: "READ-ONLY GAP ANALYSIS"
date: "2026-09-08"
author: "Engineering"
status: "No deployment · no migration · no infrastructure change · no DNS · nothing pushed"
baseline: "HEAD 6cd219e · production at migration 30 · loopback-only"
---

# D3 FINAL NETWORK-REACHABLE ENVIRONMENT GAP REPORT

## Classification

# RED — D3 BLOCKED

**Blocked by external infrastructure that does not exist, not by a decision I am
waiting on.** A production hostname, DNS, a TLS certificate and a public ingress
path must be *provisioned* before the network-reachable environment can be
prepared at all — none can be produced by configuration.

**This does not block Phase 2.7 deployment.** Deploying the release to the
existing loopback stack remains fully available and is unaffected by any item
below. That is decision **D3-c**, and it is the one I would settle first.

---

## Scope note

"Network reachable" is not port publishing. The intended architecture must keep
the boundary intact:

| Tier | Services |
|---|---|
| **PUBLIC** | HTTPS via nginx or a reverse proxy — and nothing else |
| **PRIVATE** | PostgreSQL · Redis · PHP-FPM · queue internals · scheduler internals · Mailpit · all internal Docker services |

The private tier is **already correct today** and needs no work. The public tier
does not exist.

---

## Current-state verification — 23 items

Each item carries exactly one classification.

| # | Item | Status | Evidence |
|---:|---|---|---|
| 1 | Hostname / DNS | **BLOCKED BY EXTERNAL INFRASTRUCTURE** | No domain anywhere; `APP_URL`, `FRONTEND_URL`, `SESSION_DOMAIN` all `localhost` |
| 2 | HTTPS / TLS | **BLOCKED BY EXTERNAL INFRASTRUCTURE** | nginx `listen 80` only; repo-wide search for `*.pem`/`*.crt`/`*.key`/certbot/Let's Encrypt returned nothing |
| 3 | HTTP → HTTPS behaviour | **NOT READY** | No redirect exists; nothing to redirect to |
| 4 | `APP_URL` | **NOT READY** | `http://localhost:8081` — confirmed live from the baked config cache |
| 5 | Secure session cookies | **NOT READY** | `SESSION_SECURE_COOKIE` absent |
| 6 | SameSite | **READY** | Key absent, so Laravel's `lax` default applies — correct for single-origin Sanctum, and correct under HTTPS |
| 7 | Trusted proxy | **OWNER DECISION REQUIRED** | Genuinely contingent: needed only if TLS terminates *outside* `nginx_prod` (D3-b) |
| 8 | CORS | **NOT APPLICABLE** | No `cors.php`; single-origin by design (DD-04) |
| 9 | CSRF | **READY** | `sanctum/csrf-cookie` route present; SPA cookie flow intact |
| 10 | CSP / security headers | **READY** | Strict CSP live (`default-src 'self'`, `object-src 'none'`, `frame-ancestors 'none'`) plus X-Frame-Options DENY, nosniff, Referrer-Policy, Permissions-Policy |
| 11 | Docker published ports | **READY** | Nothing improperly exposed; production publishes only `127.0.0.1:8081` and `127.0.0.1:8026` |
| 12 | Firewall assumptions | **NOT APPLICABLE** | None relied upon — loopback binding does the work. Becomes load-bearing only under D3-b |
| 13 | PostgreSQL exposure | **READY** | **No host binding at all** in production (`5432/tcp`, container-internal) |
| 14 | Redis exposure | **READY** | **No host binding at all** in production (`6379/tcp`, container-internal) |
| 15 | PHP-FPM exposure | **READY** | `9000/tcp` internal on all three app services; never published |
| 16 | Mailpit exposure | **READY** | `127.0.0.1:8026` only — not reachable off-host |
| 17 | Reverb / WebSocket exposure | **NOT APPLICABLE** | `BROADCAST_CONNECTION=null`; no Reverb package, no `REVERB_*` keys, no service |
| 18 | Queue worker | **READY** | `queue_prod` running `queue:work --tries=3 --backoff=5`; Redis-internal, no inbound |
| 19 | Scheduler | **READY** | `scheduler_prod` running `schedule:work`; 4 commands, digest at `0 23 * * *` UTC |
| 20 | Storage | **READY** | `sccit_prod_storage` → `storage/app/public`, owned `appuser:appuser`, shared read-only with nginx |
| 21 | `/up` | **READY** | Live **HTTP 200**; `health: '/up'` in `bootstrap/app.php`; deploy waits on container health before migrating |
| 22 | Production `APP_DEBUG` | **READY** | Runtime-verified `app.debug=false`, `app.env=production` |
| 23 | Production optimization readiness | **READY** | `prod-entrypoint.sh` runs `optimize:clear` → `config:cache` → `route:cache` → `event:cache` at container start; Composer `--no-dev --optimize-autoloader` |

**Tally:** 14 READY · 3 NOT READY · 1 OWNER DECISION REQUIRED · 3 NOT APPLICABLE
· 2 BLOCKED BY EXTERNAL INFRASTRUCTURE.

**A correction worth stating.** Host sockets show `127.0.0.1:5432` and
`127.0.0.1:6379` listening. Those belong to the **development** stack
(`compose.yaml` publishes them to loopback for local tooling). The **production**
stack publishes neither — production Postgres and Redis have no host binding
whatsoever. Both stacks are loopback-only regardless, so nothing is
network-exposed from either.

---

## Mail

**Real SMTP is not configured.**

| Key | Value |
|---|---|
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST` | `mailpit_prod` |
| `MAIL_PORT` | `1025` |
| `MAIL_FROM_ADDRESS` | `noreply@sccit.local` (non-routable domain) |

## **D4 — REAL SMTP CONFIGURATION REQUIRED**

Mailpit is development/test capture only. It accepts mail and never delivers it.
**Mailpit was not replaced or reconfigured.**

The consequence is not cosmetic: password resets, every FR-NOT-003 notification
email, the WP-2.7e daily digest, and the **account-lockout security email** would
all be silently swallowed. The lockout case is the sharpest — WP-2.7a made it the
only forced channel precisely because a locked-out user cannot sign in to read an
in-app message, and under Mailpit it would never arrive.

---

## Reverb

**Laravel Reverb is not part of Phase 2.7 production functionality, and does not
need public exposure for this release.** Verified four ways, not assumed:

- `BROADCAST_CONNECTION=null` in `backend/.env.production`
- No `REVERB_*` keys present
- No `laravel/reverb` in `composer.json`
- No Reverb service in `compose.prod.yaml`

FR-NOT-007 (real-time transport) is recorded in the SRS as *not implemented;
Future (P4)*. The notification badge uses WP-2.7b's 60-second visible-tab poll.
**There is no WebSocket endpoint, so none will be exposed.**

---

## Security boundary — explicit confirmation

**PostgreSQL, Redis, PHP-FPM, Mailpit and every other internal service will NOT
be publicly exposed.**

| Service | Production binding | Public? |
|---|---|---|
| PostgreSQL | none — `5432/tcp` internal | **No** |
| Redis | none — `6379/tcp` internal | **No** |
| PHP-FPM (`app_prod`, `queue_prod`, `scheduler_prod`) | none — `9000/tcp` internal | **No** |
| Mailpit | `127.0.0.1:8026` | **No** |
| nginx | `127.0.0.1:8081` | **No** (would become the sole public endpoint under D3-b) |

**No service is currently exposed. There is no security blocker.** The property
protecting Postgres and Redis is that they carry no `ports:` entry at all — that
must remain true under any future change, because it is stronger than a firewall
rule.

---

## Rollback

D1 artifacts **intact and unmodified**:

| Artifact | State |
|---|---|
| `sccit/app:prod-2df17db` | `sha256:de13d793201d…` present |
| `sccit/web:prod-2df17db` | `sha256:b8bd04e3c427…` present |
| `releases/2df17db/manifest.json` | Present |
| `releases/current` | `2df17db` |
| `releases.sh list` | `2df17db … 30 … present <- deployed` |

---

## Production safety confirmations

| Requirement | Confirmed |
|---|---|
| `APP_DEBUG` will remain `false` | **Yes** — `false` in `.env.production`, and runtime-verified `app.debug=false`. `config:cache` runs at container start, so the value is re-baked on every recreation |
| Production secrets remain outside Git | **Yes** — `backend/.env.production` is gitignored (`backend/.gitignore:5`), untracked, and **never committed** (0 commits). Only `.env.example` and `.env.production.example` templates are in history |
| `/up` used for health verification | **Yes** — live 200; `deploy-prod.sh` waits on container health before migrating |
| Laravel production optimization during deployment | **Yes** — `prod-entrypoint.sh` runs the cache chain on every container start, so it happens automatically on `--force-recreate` |
| Long-running workers reloaded after deployment | **Yes** — `queue_prod` and `scheduler_prod` are `--force-recreate`d in deploy step 5. Reverb: **not applicable** |

No secret, credential, key or token appears in this report.

---

## Gap table — every non-READY item

| Item | Status | Why | Owner Decision | Required Action |
|---|---|---|---|---|
| Hostname / DNS | BLOCKED BY EXTERNAL INFRASTRUCTURE | Everything derives from it. With `SESSION_DOMAIN=localhost` against a real hostname, **no session cookie is accepted — login fails outright**, it does not merely degrade | **D3-a:** the FQDN, and DNS pointing at this host | You provide the domain and DNS record. Then set `APP_URL`, `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`; recreate containers so `config:cache` rebuilds |
| HTTPS / TLS | BLOCKED BY EXTERNAL INFRASTRUCTURE | Sanctum admin session cookies over plain HTTP on a reachable network are readable by anyone on the path | **D3-b:** termination point and certificate source | You provide a certificate (or authorize certbot). Then terminate TLS — external proxy recommended — and add HSTS *after* HTTPS is confirmed working |
| HTTP → HTTPS redirect | NOT READY | Absent; cannot exist before TLS | Follows D3-b | Add the redirect at whichever tier terminates TLS |
| `APP_URL` | NOT READY | Builds password-reset links, signed URLs, and the action links inside notification and digest emails. Left at `localhost:8081`, every emailed link points at the recipient's own machine | Follows D3-a | Set to `https://<hostname>`; recreate containers |
| Secure session cookies | NOT READY | Without `SESSION_SECURE_COOKIE=true`, the session cookie is not marked Secure and can leak over any non-TLS request | Follows D3-b | Set `SESSION_SECURE_COOKIE=true` |
| Trusted proxy | OWNER DECISION REQUIRED | Only needed if TLS terminates outside `nginx_prod`. Without it behind a proxy, Laravel generates `http://` URLs and records the proxy's IP in the audit trail instead of the client's | **D3-b** determines whether this applies at all | If external proxy: set `TRUSTED_PROXIES` and ensure the proxy sets `X-Forwarded-Proto`/`X-Forwarded-For` |
| Real SMTP | OWNER DECISION REQUIRED | Mailpit never delivers; the forced lockout email would never arrive | **D4** | Configure `MAIL_*` for a real provider; set a routable `MAIL_FROM_ADDRESS`; align SPF/DKIM |

---

## Final classification

# RED — D3 BLOCKED

Two items are blocked by external infrastructure that cannot be created by
configuration: **a hostname with DNS**, and **a TLS certificate**. Three further
items (`HTTP→HTTPS`, `APP_URL`, secure cookies) are NOT READY but are trivial
once those exist. Two require your decision (trusted proxy, real SMTP).

The security boundary itself is **sound** — 14 of 23 items READY, nothing
improperly exposed, and Postgres, Redis, PHP-FPM and Mailpit all private.

**RED describes D3 only.** Phase 2.7 deployment to the existing loopback stack is
not blocked by anything in this report.

---

## What I need from you

**D3-a** — the production hostname and DNS (external provisioning).
**D3-b** — TLS termination point and certificate source (external provisioning).
**D3-c** — *the one to answer first:* does Phase 2.7 ship to the existing
loopback stack now, with network exposure handled as separate follow-on work?
**D4** — real SMTP provider and sender identity.

If **D3-c** is "yes, deploy to loopback", then D3-a and D3-b stop being
prerequisites for this release, and the remaining path is D4, D5 and your
deployment authorization.

No infrastructure was invented, no domain assumed, no DNS touched, no certificate
generated, no port opened, no service exposed, no configuration changed, no
deployment, no migration, no test data touched, nothing pushed. Production
remains at migration 30, loopback-only, with the D1 rollback artifacts intact.

Stopping here. D4 and D5 remain unaddressed pending your authorization.

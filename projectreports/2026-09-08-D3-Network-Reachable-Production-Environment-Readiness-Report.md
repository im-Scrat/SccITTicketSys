---
title: "D3 NETWORK-REACHABLE PRODUCTION ENVIRONMENT READINESS REPORT"
project: "SccIT — School IT Service Management System"
work_package: "Phase 2.7 — production deployment readiness (D3)"
stage: "BLOCKED ON OWNER DECISION — read-only inspection complete"
date: "2026-09-08"
author: "Engineering"
status: "No deployment · no migration · no infrastructure change · no DNS change · nothing pushed"
baseline: "HEAD 6cd219e · production at migration 30 · loopback-only"
---

# D3 NETWORK-REACHABLE PRODUCTION ENVIRONMENT READINESS REPORT

## Classification

# YELLOW — NETWORK ENVIRONMENT REQUIRES EXPLICIT OWNER DECISION

## **D3 BLOCKED — PRODUCTION HOSTNAME/DNS DECISION REQUIRED**

No hostname or domain is configured anywhere in the production environment. Per
your instruction I have **not invented a domain**, registered anything, or
touched DNS. Because every remaining item in D3 — TLS, `APP_URL`, session cookie
scope, Sanctum stateful domains, HSTS, trusted proxies — is *derived from* the
hostname, none of them can be prepared until that decision is made.

**No infrastructure or configuration change was made.** With no hostname
decided, no change was either necessary or possible.

---

## 1. Executive summary

The production stack is **correctly and deliberately loopback-only**. That is not
a defect — it is a safe posture — but it means the environment is not
network-reachable today and cannot be made so without three owner decisions.

| Finding | Severity |
|---|---|
| **N1 — No hostname/domain configured.** Everything points at `localhost:8081` | **BLOCKING** |
| **N2 — No TLS anywhere.** nginx listens on port 80 only; no certificate material exists in the repository | **BLOCKING for public exposure** |
| **N3 — Mail is Mailpit, a capture-only sink.** No real SMTP provider configured | **D4 REQUIRED** |
| N4 — Session/proxy keys absent (`SESSION_SECURE_COOKIE`, `SESSION_SAME_SITE`, `TRUSTED_PROXIES`) | Consequence of N1/N2 |

**What is already correct and needs no work:** PostgreSQL, Redis and PHP-FPM have
**no host port binding at all**; nginx and Mailpit bind to `127.0.0.1` only,
confirmed at the host socket level; security headers and a strict CSP are live;
`/up` answers 200; `APP_ENV=production` with `APP_DEBUG=false`; and the D1
rollback artifacts are intact.

**Reverb/WebSocket is not applicable** — `BROADCAST_CONNECTION=null`, no Reverb
package, no Reverb keys. FR-NOT-007 remains unimplemented, so there is no
WebSocket endpoint to route or secure.

---

## 2. Current production network architecture

Seven services on one user-defined bridge network, `sccit_prod_network`
(`driver=bridge`, `internal=false`):

| Service | Image | Host binding | Reachable from network |
|---|---|---|---|
| `nginx_prod` | `sccit/web:prod` | `127.0.0.1:8081 → 80` | **No — loopback only** |
| `app_prod` (PHP-FPM) | `sccit/app:prod` | none (`9000/tcp` internal) | No |
| `queue_prod` | `sccit/app:prod` | none | No |
| `scheduler_prod` | `sccit/app:prod` | none | No |
| `postgres_prod` | `pgvector/pgvector:pg17` | none (`5432/tcp` internal) | No |
| `redis_prod` | `redis:7-alpine` | none (`6379/tcp` internal) | No |
| `mailpit_prod` | `axllent/mailpit:latest` | `127.0.0.1:8026 → 8025` | **No — loopback only** |

Verified at the host socket level:

```
TCP  127.0.0.1:8081  LISTENING
TCP  127.0.0.1:8026  LISTENING
```

Both bind to `127.0.0.1`, **not** `0.0.0.0`. Nothing is reachable off-host.

**How it would become network-reachable.** Two viable shapes, both requiring the
hostname first: publish `nginx_prod` on `0.0.0.0:443` with TLS terminated in
nginx; or keep the loopback binding and place an external reverse proxy (Caddy,
Traefik, or host nginx) in front, terminating TLS and forwarding to
`127.0.0.1:8081`. **The second is the safer default** — it leaves this compose
file untouched and confines the change to one proxy. Neither was implemented.

**Firewall assumptions.** None are currently relied upon, because the loopback
binding does the work. Any move to `0.0.0.0` makes host firewall rules
load-bearing rather than incidental.

---

## 3. Hostname / DNS

**No hostname is configured. This is the blocker.**

| Setting | Current value |
|---|---|
| `APP_URL` | `http://localhost:8081` |
| `FRONTEND_URL` | `http://localhost:8081` |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:8081,localhost,127.0.0.1:8081` |
| `SESSION_DOMAIN` | `localhost` |
| nginx `server_name` | `_` (catch-all) |

There is no domain to resolve, so DNS resolution, destination correctness and
stale-record checks are all **not applicable** until a hostname exists. No DNS
record was created, modified or queried against a real domain.

**Required decision — D3-a:** the fully-qualified hostname (for example
`sccit.<school-domain>`), and whether it resolves to this host.

---

## 4. HTTPS / TLS

**No TLS exists.** The nginx configuration baked into `sccit/web:prod` contains:

```
listen 80;
server_name _;
root /usr/share/nginx/html;
```

No `listen 443`, no `ssl_certificate`, no HTTP→HTTPS redirect, no `proxy_pass`.
A repository-wide search for `*.pem`, `*.crt`, `*.key`, certbot or Let's Encrypt
material returned **nothing**.

| Item | State |
|---|---|
| TLS termination point | **Not decided** |
| Certificate source | **None** |
| Certificate validity | n/a |
| HTTP → HTTPS redirect | **Absent** |
| Secure cookies | **Absent** (§5) |
| Trusted proxies | **Unconfigured** (§5) |
| WebSocket TLS | n/a — no Reverb |

**Classified as a deployment blocker for public exposure, not silently
accepted.** Serving authenticated production traffic — Sanctum session cookies
carrying administrator privileges — over plain HTTP on a reachable network would
expose those cookies to anyone on the path. TLS verification was not disabled
anywhere.

**Required decision — D3-b:** where TLS terminates and where certificates come
from.

---

## 5. Application URL and session security

| Key | Current | Required under HTTPS |
|---|---|---|
| `APP_URL` | `http://localhost:8081` | `https://<hostname>` |
| `FRONTEND_URL` | `http://localhost:8081` | `https://<hostname>` |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:8081,localhost,127.0.0.1:8081` | `<hostname>` |
| `SESSION_DOMAIN` | `localhost` | `<hostname>` |
| `SESSION_SECURE_COOKIE` | **absent** | `true` |
| `SESSION_SAME_SITE` | **absent** (Laravel defaults to `lax`) | `lax` — correct for single-origin |
| `SESSION_ENCRYPT` | `false` | acceptable; Redis-backed sessions are server-side |
| `TRUSTED_PROXIES` | **absent** | required behind a TLS terminator |
| `ASSET_URL` | absent | not required — assets are same-origin |
| CORS | no `cors.php` | **none needed** — single-origin by design (DD-04) |
| CSRF | Sanctum SPA cookie flow | unchanged, but depends on the domains above |

**Why this matters beyond configuration tidiness.** `APP_URL` is not cosmetic:
it builds password-reset links, signed URLs and the action links inside
notification and daily-digest emails. Deployed to a real hostname with `APP_URL`
left at `localhost:8081`, every emailed link would point at the recipient's own
machine. And with `SESSION_DOMAIN=localhost` against a real hostname, **no
session cookie would be accepted at all** — authentication would fail outright.

Without a trusted-proxy entry behind a TLS terminator, Laravel sees the proxy's
address and scheme, generating `http://` URLs and mis-recording client IPs in
the audit trail.

No secret or credential is reproduced in this report.

---

## 6. Reverse proxy

**None exists.** `nginx_prod` is the only HTTP server, and it serves the SPA and
proxies to PHP-FPM inside the compose network. There is no outer proxy, no
`X-Forwarded-*` handling, and no `TRUSTED_PROXIES` entry.

If an external terminator is introduced (the recommended shape in §2), it must
set `X-Forwarded-Proto` and `X-Forwarded-For`, and Laravel must be configured to
trust it — otherwise the two failure modes in §5 both occur.

---

## 7. Docker network exposure

| Port | Bound to | Assessment |
|---|---|---|
| 8081 → nginx | `127.0.0.1` | Intended public endpoint **once** TLS and a hostname exist |
| 8026 → Mailpit UI | `127.0.0.1` | **Must remain private** |
| PHP-FPM 9000 | unpublished | Correct |
| PostgreSQL 5432 | unpublished | Correct |
| Redis 6379 | unpublished | Correct |

The intended public attack surface is exactly **one** endpoint — HTTPS on the
chosen hostname. No WebSocket endpoint is required. Nothing was exposed during
this inspection.

---

## 8. Redis / PostgreSQL security

Both remain **internal-only**, and there is no documented reason to change that.

- Neither publishes a host port; both are reachable only by service name on
  `sccit_prod_network`.
- `REDIS_PASSWORD` and `DB_PASSWORD` are set in `backend/.env.production`
  (values not read or reproduced here).
- Persistence is on named volumes (`sccit_prod_pgdata`, `sccit_prod_redisdata`).

**Neither will be exposed.** If the stack ever moves to `0.0.0.0` publishing,
these two services must keep no `ports:` entry at all — that is the property
protecting them, not a firewall rule.

---

## 9. Mail / SMTP

| Key | Value |
|---|---|
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST` | `mailpit_prod` |
| `MAIL_PORT` | `1025` |
| `MAIL_SCHEME` | `null` |
| `MAIL_FROM_ADDRESS` | `noreply@sccit.local` |

## **D4 REQUIRED — REAL SMTP CONFIGURATION**

Mailpit **captures and never delivers**. Every password reset, every FR-NOT-003
notification email, the account-lockout security email and the entire WP-2.7e
daily digest would be silently swallowed. The lockout email is the sharpest
case: a locked-out user cannot sign in to read an in-app message, which is
precisely why WP-2.7a made it a forced channel — and it would never arrive.

**Mailpit was not replaced or reconfigured.** `MAIL_FROM_ADDRESS` also uses the
non-routable `sccit.local` domain and would need to become a real sender on the
chosen domain, with SPF/DKIM alignment.

---

## 10. Queue / scheduler

Both verified and require **no change** for network reachability — they make no
inbound connections.

| Service | Command | State |
|---|---|---|
| `queue_prod` | `queue:work --tries=3 --backoff=5 --max-time=3600 --sleep=3` | Running |
| `scheduler_prod` | `schedule:work` | Running |

`QUEUE_CONNECTION=redis`; four scheduled commands are registered, with the
WP-2.7e digest at `0 23 * * *` UTC = 07:00 Asia/Manila. Both consume Redis over
the internal network only.

---

## 11. Reverb / WebSocket

**Not applicable — and verified, not assumed.**

- `BROADCAST_CONNECTION=null`
- No `REVERB_*` keys in `backend/.env.production`
- No `laravel/reverb` package in `composer.json`
- No Reverb service in `compose.prod.yaml`
- FR-NOT-007 is recorded in the SRS as *not implemented; Future (P4)*

There is no WebSocket endpoint to route, secure, or authorize. The notification
badge uses the 60-second visible-tab poll delivered by WP-2.7b.

---

## 12. Storage

| Item | State |
|---|---|
| `FILESYSTEM_DISK` | `local` |
| Volume | `sccit_prod_storage` → `/var/www/html/storage/app/public` |
| Ownership | `appuser:appuser` (uid/gid 1000) — matches the runtime user |
| Nginx access | Shares the volume read-only so `/storage` serves uploads |
| `storage/logs`, `bootstrap/cache` | Present and writable by `appuser` |

Correct as-is. Public exposure introduces no new storage requirement, since
uploads are served through the same origin behind authentication.

---

## 13. Security headers / CORS / CSP

Live response headers from `:8081`:

```
Content-Security-Policy: default-src 'self'; script-src 'self' 'sha256-…';
  style-src 'self'; img-src 'self' data: blob:; font-src 'self' data:;
  connect-src 'self'; object-src 'none'; base-uri 'none';
  form-action 'self'; frame-ancestors 'none'
X-Frame-Options: DENY
X-Content-Type-Options: nosniff
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()
```

Strict and correct. **CORS is deliberately absent** — the application is
single-origin (DD-04), so no cross-origin allowance is needed or wanted.

**`Strict-Transport-Security` is absent, and that is currently correct** — HSTS
over plain HTTP is meaningless, and setting it before TLS works would be
actively harmful. It becomes **required** the moment HTTPS is live, and should
be added with a short `max-age` first, then raised.

---

## 14. Health check

| Check | Result |
|---|---|
| `/up` configured | `health: '/up'` in `bootstrap/app.php` |
| Live response | **HTTP 200** in ~0.22s |
| Boot-failure behaviour | The route fails with the application, so a failed boot is observable |
| Container health | `app_prod`, `nginx_prod`, `postgres_prod`, `redis_prod`, `mailpit_prod` all healthy; `queue_prod`/`scheduler_prod` are workers with the FPM check disabled by design |
| Deployment use | `deploy-prod.sh` waits for `app_prod` healthy before migrating |

`/up` is a boot check. It does not itself assert database, Redis or queue health
— those are separate runbook steps. No new release was deployed.

---

## 15. Production optimization readiness

Already implemented, and correctly placed.

| Mechanism | Where |
|---|---|
| `optimize:clear` → `config:cache` → `route:cache` → `event:cache` | `docker/php/prod-entrypoint.sh`, at **container start** |
| Composer optimized autoloader | `composer install --no-dev --optimize-autoloader` in `docker/php/Dockerfile` |
| Live cache artifacts | `config.php`, `routes-v7.php`, `events.php`, `packages.php`, `services.php` present in `bootstrap/cache` |
| Queue worker reload | `--force-recreate` on `queue_prod` during deploy |
| Reverb reload | n/a |

Caching at **start** rather than build is deliberate and important: `config:cache`
bakes the *current* environment, so an image built once can still pick up changed
env values. This is exactly why an `APP_URL` change would take effect on
container recreation without a rebuild.

`view:cache` is not run. That is reasonable — this is an API plus a compiled SPA,
with only `welcome.blade.php` and framework mail templates. Worth adding for
completeness, not a blocker.

**Nothing was executed.**

---

## 16. Rollback artifact verification

The D1 artifacts are **intact and unmodified**:

| Artifact | State |
|---|---|
| `sccit/app:prod-2df17db` | `sha256:de13d793201d…` — present |
| `sccit/web:prod-2df17db` | `sha256:b8bd04e3c427…` — present |
| `releases/2df17db/manifest.json` | Present |
| `releases/current` | `2df17db` |
| `releases.sh list` | `2df17db … 30 … present <- deployed` |

Still usable as the previous-release rollback target. Nothing was modified or
deleted.

---

## 17. Database deployment readiness

**No migration was run and the production database was not modified.** It remains
at **30** migrations.

The eventual deployment will, in this order:

1. create and verify the pre-deploy snapshot (`deploy-prod.sh` step 3, before any
   schema change; its failure aborts the deploy);
2. build and deploy the Phase 2.7 images;
3. run migrations **31 then 32** in filename order, in one `migrate --force`;
4. verify success via `migrate:status` → **32 Ran**;
5. perform health checks (`/up`, containers, database, Redis, queue, scheduler);
6. perform the D2 authenticated smoke tests.

Migrations stay a controlled release operation and are deliberately **not** mixed
into this environment-preparation step.

---

## 18. Remaining blockers

| # | Issue | Why it matters | Decision required | Remediation | Needs new authorization? |
|---|---|---|---|---|---|
| **N1** | No hostname/domain configured | Everything else derives from it; `SESSION_DOMAIN=localhost` means **no session cookie is accepted** on a real hostname, so login fails outright | The FQDN, and confirmation it resolves here | Set `APP_URL`, `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`; recreate containers so `config:cache` rebuilds | **Yes** |
| **N2** | No TLS | Authenticated admin session cookies over plain HTTP on a reachable network are exposed to anyone on the path | Termination point and certificate source | Terminate TLS (external proxy recommended); add HTTP→HTTPS redirect; set `SESSION_SECURE_COOKIE=true` and `TRUSTED_PROXIES`; add HSTS after TLS is confirmed | **Yes** |
| **N3** | Mailpit is capture-only | Password resets, lockout security emails and the entire daily digest are silently swallowed | Real SMTP provider and sender identity | Configure `MAIL_*` for the provider; set a routable `MAIL_FROM_ADDRESS`; align SPF/DKIM | **Yes — this is D4** |
| N4 | Session/proxy keys absent | Consequence of N1/N2 | — | Applied together with N1/N2 | Covered above |

**Not blockers:** loopback binding (correct today), absent CORS (by design),
absent HSTS (correct without TLS), absent Reverb (FR-NOT-007 unimplemented),
`view:cache` (negligible for this app).

---

## 19. D3 GO / NO-GO

# YELLOW — NETWORK ENVIRONMENT REQUIRES EXPLICIT OWNER DECISION

## **D3 BLOCKED — PRODUCTION HOSTNAME/DNS DECISION REQUIRED**

Against §11's acceptance criteria:

| Criterion | Met |
|---|---|
| Intended hostname known | **✘ N1** |
| DNS correct | ✘ — n/a until N1 |
| HTTPS/TLS ready | **✘ N2** |
| HTTP redirects appropriately | ✘ — n/a until N2 |
| `APP_URL` correct | ✘ — `localhost:8081` |
| Secure session behaviour configured | ✘ — keys absent |
| Trusted proxy behaviour correct | ✘ — unconfigured |
| WebSocket/Reverb routing ready | ✔ **n/a** — not implemented |
| Real SMTP configured or explicitly accepted | **✘ — D4** |
| Only intended public ports exposed | ✔ loopback-only |
| PostgreSQL private | ✔ |
| Redis private | ✔ |
| Mailpit private | ✔ |
| Internal services private | ✔ |
| Health architecture ready | ✔ |
| Rollback release available | ✔ |

Seven of sixteen are met; the rest all descend from N1. **This is not RED** — the
environment is not unsafe, it is safe precisely because it is unreachable. It is
simply not yet the environment you described.

---

## 20. Required owner decisions

**D3-a — Production hostname (blocking).** The FQDN, and confirmation that DNS
for it points at this host. I will not invent, register or resolve a domain.

**D3-b — TLS architecture (blocking for public exposure).** Where TLS terminates
— in `nginx_prod` on published 443, or in an external reverse proxy forwarding
to `127.0.0.1:8081` — and where certificates come from (Let's Encrypt/certbot,
or a provided certificate). *Recommendation: external proxy. It leaves
`compose.prod.yaml` and both images untouched, so the Phase 2.7 release remains
exactly what was verified.*

**D3-c — Is public exposure wanted for this release at all?** A defensible
alternative is to deploy Phase 2.7 to the existing loopback stack, run the D2
smoke tests there, and treat network exposure as separate follow-on work. That
would let Phase 2.7 ship on verified ground while N1–N3 are resolved properly,
rather than coupling a release to an infrastructure change.

**D4 — Real SMTP** (already raised at the release gate, re-confirmed here).

---

## 21. Exact next action

**Answer D3-c first**, because it determines whether D3-a and D3-b are needed
now or later.

- If Phase 2.7 deploys to the **existing loopback stack**: D3 is not a
  prerequisite. The next action is your deployment authorization, and the release
  gate's remaining items are D4 and D5.
- If Phase 2.7 must be **publicly reachable**: D3-a and D3-b must be answered
  first, then a separate authorization to apply the hostname, TLS, session and
  proxy configuration — followed by re-running this D3 verification before any
  deployment.

Nothing was deployed, no migration was run, no infrastructure or DNS was
changed, no test account or data was touched, and nothing was pushed. Production
remains at migration 30, loopback-only, with the D1 rollback artifacts intact.

Stopping here. D4 and D5 remain unaddressed pending your authorization.

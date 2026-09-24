---
title: "D3-c ACCEPTANCE / DEPLOYMENT TRANSITION REPORT"
project: "SccIT — School IT Service Management System"
work_package: "Phase 2.7 — production deployment readiness (D3-c transition)"
stage: "TRANSITION RECORDED — read-only"
date: "2026-09-08"
author: "Engineering"
status: "No deployment · no migration · no data deleted · no public port · nothing pushed"
baseline: "HEAD 6cd219e · production at migration 30 · loopback-only"
---

# D3-c ACCEPTANCE / DEPLOYMENT TRANSITION REPORT

## The twelve confirmations

| # | Confirmation | State | Evidence |
|---:|---|---|---|
| 1 | **D3-c accepted** | ✔ | Phase 2.7 proceeds to the existing loopback production stack; the absence of hostname, DNS, TLS and public ingress does not block it |
| 2 | **Loopback deployment path accepted** | ✔ | Target is `127.0.0.1:8081`. **This is explicitly not the final network-reachable release** and will not be represented as one |
| 3 | **D3-a deferred** | ✔ | Production FQDN and DNS record — recorded as **deferred external infrastructure**. No domain invented, no DNS touched |
| 4 | **D3-b deferred** | ✔ | TLS certificate, termination architecture, HTTP→HTTPS redirect, secure-cookie and trusted-proxy configuration — **deferred external infrastructure**. No certificate generated |
| 5 | **D4 required before final user-facing release** | ✔ | Mailpit acceptable for loopback/test capture only. **Not replaced.** Real SMTP is a separate authorized step |
| 6 | **D5 pending** | ✔ | Rollback rehearsal remains its own gate. **Not performed** |
| 7 | **D1 rollback artifacts intact** | ✔ | `sccit/app:prod-2df17db` `sha256:de13d793201d…`; `sccit/web:prod-2df17db` `sha256:b8bd04e3c427…`; `releases/2df17db/manifest.json` present; `releases/current` = `2df17db` |
| 8 | **Production at migration 30** | ✔ | `migrations` table: **30**; production image carries **30** migration files |
| 9 | **No deployment has occurred** | ✔ | `sccit/app:prod` and `sccit/web:prod` still resolve to the pre-Phase-2.7 IDs — identical to `prod-2df17db` |
| 10 | **No migration has occurred** | ✔ | 30 applied, unchanged. Migrations 31 and 32 remain unrun and absent from the production image |
| 11 | **No data deleted** | ✔ | `users=1`, `activity_logs=8`, `tickets=0` — unchanged since the release gate |
| 12 | **No public ports opened** | ✔ | **0** bindings on `0.0.0.0`. Only `127.0.0.1:8081` (nginx) and `127.0.0.1:8026` (Mailpit) are published |

---

## Security boundary — preserved exactly as verified

| Tier | Services | Binding |
|---|---|---|
| **Loopback (host-reachable only)** | nginx, Mailpit UI | `127.0.0.1:8081`, `127.0.0.1:8026` |
| **PRIVATE — no host binding at all** | PostgreSQL, Redis, PHP-FPM (`app_prod`, `queue_prod`, `scheduler_prod`) | `5432/tcp`, `6379/tcp`, `9000/tcp` — container-internal |

PostgreSQL, Redis, PHP-FPM, Mailpit and every internal Docker service remain
unexposed. The property protecting Postgres and Redis is that they carry **no
`ports:` entry at all** — stronger than a firewall rule, and it must stay that
way through deployment and any future exposure work.

---

## Transition path

```
D3 COMPLETE  →  D4  →  D5  →  FINAL DEPLOYMENT AUTHORIZATION
```

| Stage | State | What it covers |
|---|---|---|
| **D1** | ✔ Complete | Rollback target created and verified |
| **D2** | ✔ Complete | Authenticated smoke-test plan prepared (not executed) |
| **D3** | ✔ **Complete via D3-c** | Loopback deployment accepted; D3-a and D3-b deferred as external infrastructure |
| **D4** | **Next** | Real SMTP — required before the final user-facing release, not before the loopback deployment |
| **D5** | Pending | Rollback rehearsal, closing WP-2.7d's F-2 |
| **Final** | Pending | Your explicit **AUTHORIZE PRODUCTION DEPLOYMENT** |

**One sequencing note worth your judgement.** D4 (real SMTP) is required before
the *final user-facing* release, but the loopback deployment does not depend on
it — Mailpit captures mail exactly as the D2 smoke plan expects, and NOT-6 and
NOT-12 verify *what was captured*. If you would rather order the remaining gates
as **D5 → deployment → D4**, that is defensible: it closes the rollback rehearsal
before the first real release, and defers SMTP to the network-exposure
workstream where it naturally belongs alongside D3-a and D3-b. I am not acting on
that — it is yours to decide.

---

## Deferred workstream — recorded, not actioned

**Network / public exposure** is now a separate follow-on infrastructure
workstream comprising:

- **D3-a** — production FQDN and DNS record
- **D3-b** — TLS certificate, termination architecture, HTTP→HTTPS redirect,
  `SESSION_SECURE_COOKIE`, trusted-proxy configuration
- **D4** — real SMTP provider and routable sender identity
- Consequent configuration: `APP_URL`, `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`,
  `SESSION_DOMAIN`, HSTS

None was invented, assumed or applied.

---

## Status

Nothing was deployed, no migration was run, no data was created or deleted, no
public port was opened, no domain or certificate was invented, no DNS was
modified, Mailpit was not replaced, no rollback was rehearsed, no application
code changed, and nothing was pushed.

Production remains at **migration 30**, **loopback-only**, with the D1 rollback
artifacts intact and `sccit/{app,web}:prod` still resolving to the pre-Phase-2.7
release.

Stopping here. Awaiting your next authorization — D4, D5, or a change to the gate
order.

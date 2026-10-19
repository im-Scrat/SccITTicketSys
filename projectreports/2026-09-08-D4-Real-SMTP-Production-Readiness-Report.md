---
title: "D4 REAL SMTP PRODUCTION READINESS REPORT"
project: "SccIT — School IT Service Management System"
work_package: "Phase 2.7 — production deployment readiness (D4)"
stage: "READ-ONLY INVESTIGATION"
status: "No deployment · no migration · Mailpit not replaced · no provider selected · nothing pushed"
date: "2026-09-08"
author: "Engineering"
baseline: "HEAD 6cd219e · production at migration 30 · loopback-only"
---

# D4 REAL SMTP PRODUCTION READINESS REPORT

## Classification

# RED — D4 BLOCKED

## **D4 BLOCKED — REAL SMTP PROVIDER/ACCOUNT REQUIRED**

No real SMTP provider exists anywhere in this project. Mailpit is the **active
production mail transport today**. No provider was registered, selected or
configured, and Mailpit was not replaced.

**This does not block the loopback deployment** agreed under D3-c — Mailpit
captures mail exactly as the D2 smoke plan expects. It blocks the final
user-facing release.

---

## 1. Executive summary

The good news is that the switch itself is **configuration-only**: `config/mail.php`
is stock Laravel, entirely env-driven, so moving to a real relay requires **no
application code change and no new dependency** — provided the provider speaks
plain SMTP.

What is missing is the provider itself, and the domain identity it would send
as. Both are external things you must supply.

| Finding | Severity |
|---|---|
| **M1 — No real SMTP provider exists.** Host is `mailpit_prod:1025` in production | **BLOCKING for user-facing release** |
| **M2 — No SMTP authentication.** Username and password are both empty | Consequence of M1 |
| **M3 — No transport encryption.** `MAIL_SCHEME` is null; port 1025 is plaintext | Consequence of M1 |
| **M4 — Sender is non-routable.** `noreply@sccit.local` — `.local` cannot receive or be validated | **Owner decision** |
| **M5 — No SPF / DKIM / DMARC.** No sending domain exists to align | **Blocked on D3-a** |
| **M6 — Queued security email.** The account-lockout email is queued, so a stopped worker delays the one notification designed to bypass silencing | **Worth a decision** |

---

## 2. Current mail architecture

Read from the running production container. **All credentials masked; both are
empty in any case.**

| Setting | Value |
|---|---|
| `MAIL_MAILER` / `mail.default` | `smtp` |
| SMTP host | `mailpit_prod` |
| SMTP port | `1025` |
| Encryption / scheme | `(null)` — **no TLS** |
| `MAIL_URL` | `(empty)` |
| Authentication | **none** — username `(empty)`, password `(empty)` |
| `local_domain` (EHLO) | `localhost` |
| Sender address | `noreply@sccit.local` |
| Sender name | `SccIT` |
| Timeout | `(null)` — Symfony default |

**Is Mailpit the active production transport? Yes.** `app_prod`, `queue_prod`
and `scheduler_prod` all resolve `mailpit_prod` on the internal network, and
Mailpit accepts every message and delivers none. Its UI is bound to
`127.0.0.1:8026`.

Mail-related keys present in `backend/.env.production`: `MAIL_MAILER`,
`MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`, `MAIL_PASSWORD`,
`MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`. No value is reproduced here.

---

## 3. Application email inventory

Every implemented path that sends mail, with its actual delivery semantics.

| # | Email | Class | Queued? | Redis / worker | Retry | Failure handling |
|---:|---|---|---|---|---|---|
| 1 | **Password reset** | Laravel `ResetPassword` (URL customised in `AppServiceProvider`) | **No — synchronous** | No | None | Exception surfaces to the request |
| 2 | **Admin-triggered password reset** | `AdminSendPasswordReset` → `Password::sendResetLink` | **No — synchronous** | No | None | Same |
| 3 | **Account lockout (security)** | `AccountLocked extends ProjectNotification` | **Yes** | **Yes** | `--tries=3 --backoff=5` | `failed_jobs` after 3 attempts |
| 4 | **All FR-NOT-003 trigger emails** (10 triggers) | subclasses of `ProjectNotification` | **Yes** | **Yes** | `--tries=3 --backoff=5` | `failed_jobs`; per-recipient isolation (DD-59) |
| 5 | **Daily digest** (WP-2.7e) | `DailyDigestNotification extends Notification` | **No — synchronous, by design** | No | **None — at-most-once** | Logged, counted, **never retried** |
| 6 | **Registration submitted** | `RegistrationSubmitted` | **No — synchronous** | No | None | Exception surfaces |
| 7 | **Registration approved** | `RegistrationApproved` | **No — synchronous** | No | None | Exception surfaces |
| 8 | **Registration rejected** | `RegistrationRejected` | **No — synchronous** | No | None | Exception surfaces |
| 9 | **Admin message to user** | `AdminMessage` | **No — synchronous** | No | None | Exception surfaces |

No `Mailable` classes exist; every email is a Laravel notification. Email
verification is **not implemented**, so there is no verification email.

**Two observations that matter for provider choice.**

**Six of nine paths are synchronous.** Password reset, registration mail and
admin messages are sent on the request thread. A slow or unreachable relay
therefore becomes a slow or failing HTTP request for the user — not a background
retry. Any provider must be reliable and reasonably fast, and `MAIL_TIMEOUT`
should be set rather than left null.

**The digest is deliberately not retried.** WP-2.7e is at-most-once: the claim
commits before SMTP, so a relay failure costs that user that day's digest. That
is the accepted design, and it means **relay reliability directly determines
digest delivery** with no safety net.

---

## 4. Queue / delivery architecture

| Item | Value |
|---|---|
| `QUEUE_CONNECTION` | `redis` |
| Worker | `queue_prod`: `queue:work --tries=3 --backoff=5 --max-time=3600 --sleep=3` |
| `retry_after` | 90s |
| `after_commit` | `false` at connection level (`ProjectNotification` sets `$afterCommit` itself) |
| Failed jobs | `failed_jobs` table — production **0**, dev 27 (WP-2.7a-era residue) |
| Worker availability | Running; recreated on every deploy |

**Could deployment interrupt queued email?** Briefly, and safely.
`deploy-prod.sh` `--force-recreate`s `queue_prod`. A job in flight when the
container stops is not acknowledged, so Redis re-queues it after `retry_after`
(90s) and the new worker picks it up. **No queued email is lost**; some may be
delayed by up to ~90 seconds. `--max-time=3600` also recycles the worker hourly
by design.

**Reload requirement:** none beyond the existing `--force-recreate`, which
already loads the new code and any changed `MAIL_*` values (the entrypoint
re-runs `config:cache` at container start).

---

## 5. Real SMTP provider status

## **NOT READY — no provider exists**

| Check | Result |
|---|---|
| Provider configured in `.env.production` | **No** — `mailpit_prod:1025` |
| Provider in `.env.production.example` | **No** — same Mailpit values |
| Provider SDK / transport package in `composer.json` | **None** — no SES, Postmark, Mailgun, SendGrid or Resend package |
| Credentials present | **None** — username and password empty |

**A useful constraint, discovered rather than assumed.** `config/mail.php` offers
`smtp`, `ses`, `postmark`, `resend`, `sendmail`, `log`, `array`, `failover` and
`roundrobin`. But `ses`, `postmark` and `resend` each require their Symfony
bridge package, and **none is installed**. So today:

- **A plain SMTP relay needs zero code and zero dependency change** — env only.
- **An API-based provider requires adding a Composer package**, which means an
  image rebuild and a code change I am not authorized to make.

That materially favours a provider offering standard SMTP.

**No provider was registered or selected.**

---

## 6. SMTP security requirements

| Requirement | Current | Required for production |
|---|---|---|
| Transport encryption | **None** (`scheme` null, port 1025) | TLS — `MAIL_SCHEME=smtps` (465) or STARTTLS (587) |
| SMTP authentication | **None** (empty credentials) | Username + password/API key from the provider |
| Sender identity | `noreply@sccit.local` — **non-routable** | A real mailbox on the production domain |
| EHLO domain | `localhost` | Should match the sending host |
| Timeout | `(null)` | Set explicitly — six paths are synchronous (§3) |
| Rate limiting | None in the application | Provider-side; matters for `audience = all` announcements and the daily digest, which fan out to every active user in one run |
| Bounce handling | **None implemented** | Provider-side; the application has no bounce ingestion and none is planned in Phase 2.7 |
| Secret storage | See §9 | Unchanged mechanism |

**Do not disable TLS verification** — nothing in the current configuration does,
and nothing should.

---

## 7. SPF / DKIM / DMARC requirements

## **BLOCKED BY EXTERNAL INFRASTRUCTURE**

All three are DNS records on a sending domain. **There is no domain** — D3-a is
deferred, and `sccit.local` is a non-routable reserved TLD that cannot hold
public DNS records or be validated by any receiver.

Once D3-a provides a domain, the required work is:

| Record | Purpose |
|---|---|
| **SPF** | TXT authorising the provider's sending hosts |
| **DKIM** | Provider-issued public key at the selector they specify |
| **DMARC** | Policy record; start `p=none` with reporting, tighten to `quarantine`/`reject` once aligned |
| **Alignment** | `MAIL_FROM_ADDRESS` domain must match the SPF/DKIM domain, or DMARC fails even when both pass |

**Without these, mail will be spam-foldered or rejected outright** — particularly
consequential for password resets and the lockout security email, which users
must actually receive.

**D4 is therefore dependent on D3-a.** SMTP credentials can be configured
without a domain; trustworthy delivery cannot.

---

## 8. Mailpit separation strategy

**Mailpit stays. It is not to be removed** — it is the correct tool for
development and for the loopback smoke tests.

The clean separation already exists structurally, because mail is fully
env-driven and each stack has its own env file:

| Environment | Stack | Transport |
|---|---|---|
| Development | `compose.yaml` | Mailpit (`mailpit:1025`, UI `127.0.0.1:8025`) |
| **Loopback production (D3-c)** | `compose.prod.yaml` | **Mailpit** (`mailpit_prod:1025`, UI `127.0.0.1:8026`) — accepted for this release |
| Automated tests | Pest | `Mail::fake()` — never touches a transport |
| **Future user-facing production** | `compose.prod.yaml` | **Real SMTP** via `MAIL_*` in `backend/.env.production` |

**The switch is four env values** — `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`,
`MAIL_USERNAME`/`MAIL_PASSWORD` — plus `MAIL_FROM_ADDRESS`. No code change, no
image rebuild, and `config:cache` re-bakes them on container recreation.

**Recommendation for when that happens:** keep `mailpit_prod` in
`compose.prod.yaml` rather than deleting the service. It costs ~20 MB, remains
loopback-only, and gives a local capture target if the relay ever needs to be
bypassed for diagnosis. Removing it would be a compose change with no benefit.

**No switch was implemented.** This section is a plan.

---

## 9. Production secret handling

**READY — and verified, not assumed.**

| Check | Result |
|---|---|
| `backend/.env.production` gitignored | **Yes** — `backend/.gitignore:5` |
| Tracked in Git | **No** — 0 files |
| Ever committed | **No** — 0 commits touching it |
| In history | Only `.env.example` and `.env.production.example` templates |
| Delivered to containers | `env_file:` in `compose.prod.yaml`; never baked into an image |
| Cached at runtime | `config:cache` at container start, inside the container only |

SMTP credentials would be added to the same file and inherit the same handling.
`MAIL_PASSWORD` is already declared there with an empty value, so **no new
secret-management mechanism is needed** — the slot exists.

No secret, credential, key or token appears in this report.

---

## 10. Email smoke-test plan

**Prepared, not executed.** Two tiers, because the loopback release and the
user-facing release verify different things.

### Tier 1 — loopback release (Mailpit) — usable now

| # | Check | Expected |
|---|---|---|
| E-1 | Trigger a password reset | Message appears in Mailpit `:8026` with a working `https?://…/reset-password?token=…` link |
| E-2 | Verify the reset link host | Matches `FRONTEND_URL` — catches the `APP_URL` trap from D3 |
| E-3 | Lock an account (failed sign-ins) | Lockout email captured; confirms the forced channel survives the queue |
| E-4 | Trigger a ticket assignment | Notification email captured for the assignee only |
| E-5 | Publish an announcement | **No email captured** — the DD-66 exclusion |
| E-6 | `notifications:send-digest --date <yesterday>` | One message per opted-in user, **titles only, no message bodies** |
| E-7 | Re-run E-6 | **Nothing sent** — at-most-once holds |
| E-8 | `failed_jobs` after E-1…E-7 | Still **0** |

### Tier 2 — user-facing release (real SMTP) — after D4 is unblocked

| # | Check | Expected |
|---|---|---|
| E-9 | Send to an **external** mailbox you control | Arrives in the inbox, not spam |
| E-10 | Inspect received headers | `spf=pass`, `dkim=pass`, `dmarc=pass` |
| E-11 | Confirm the From address | The real domain sender, not `sccit.local` |
| E-12 | Send to an invalid address | Failure is visible — in `failed_jobs` for queued paths, as a request error for synchronous ones |
| E-13 | Digest to a real mailbox | Renders correctly in a real client |
| E-14 | Synchronous path under a slow relay | Password reset still returns within an acceptable time — the `MAIL_TIMEOUT` check |

---

## 11. Remaining blockers

| # | Item | Status | Why it matters | Owner decision | Action | New authorization? |
|---|---|---|---|---|---|---|
| M1 | No SMTP provider | **NOT READY** | Mailpit never delivers; password resets and the lockout security email silently vanish | **D4-a:** which provider | Configure `MAIL_*` in `.env.production`; recreate containers | **Yes** |
| M2 | No SMTP auth | **NOT READY** | Any real relay requires credentials | Follows D4-a | Set `MAIL_USERNAME` / `MAIL_PASSWORD` | Covered by D4-a |
| M3 | No TLS on SMTP | **NOT READY** | Credentials and message content would cross the network in clear | Follows D4-a | `MAIL_SCHEME=smtps` (465) or STARTTLS (587) | Covered by D4-a |
| M4 | Non-routable sender | **OWNER DECISION REQUIRED** | `.local` cannot be validated; guarantees spam classification | **D4-b:** the From address | Set `MAIL_FROM_ADDRESS` on the real domain | **Yes** |
| M5 | No SPF/DKIM/DMARC | **BLOCKED BY EXTERNAL INFRASTRUCTURE** | Without alignment, mail is spam-foldered or rejected | Depends on **D3-a** | Publish DNS records after the domain exists | **Yes** |
| M6 | Lockout email is queued | **OWNER DECISION REQUIRED** | It is the one notification that bypasses preferences *because* a locked-out user cannot read in-app — yet a stopped worker delays it | **D4-c:** accept, or send it synchronously | Accept as-is, or a small code change (out of scope now) | **Yes, if changed** |
| — | Secret handling | **READY** | — | — | — | — |
| — | Queue architecture | **READY** | — | — | — | — |
| — | Mailpit separation | **READY** (as a plan) | — | — | — | — |

---

## 12. D4 GO / NO-GO

# RED — D4 BLOCKED

## **D4 BLOCKED — REAL SMTP PROVIDER/ACCOUNT REQUIRED**

D4 cannot be GREEN because GREEN requires that "the real SMTP provider/account
exists" — and none does. No infrastructure was manufactured to make it appear
otherwise.

**Scope of the block, stated precisely:** D4 blocks the **final user-facing
release**. It does **not** block the loopback deployment accepted under D3-c,
where Mailpit is the intended and sufficient transport and the Tier-1 smoke
tests above are fully executable.

---

## 13. Exact owner decisions required

**D4-a — Which SMTP provider (blocking).** Please supply the provider and its
credentials. **Strong preference for one offering standard SMTP** — any
API-based provider (SES, Postmark, Resend) needs a Composer package added,
meaning a code change and image rebuild, whereas plain SMTP is env-only.

**D4-b — Sender identity.** The From address on the real domain, e.g.
`noreply@<domain>`, replacing `noreply@sccit.local`.

**D4-c — The queued lockout email.** It inherits `ShouldQueue` from
`ProjectNotification`. It is the *only* notification that bypasses user
preferences, precisely because a locked-out user cannot read an in-app message —
yet a stopped queue worker delays exactly that. Accept as-is, or schedule a
change to send it synchronously? I have not changed it.

**Dependency:** D4 is partly gated by **D3-a**. Credentials can be set without a
domain; SPF/DKIM/DMARC alignment cannot, and without alignment real delivery is
unreliable regardless of provider.

---

## 14. Recommended next action

**Proceed to D5 (rollback rehearsal) and the loopback deployment first, leaving
D4 for the network-exposure workstream.**

The reasoning: D4 is blocked on infrastructure you must procure, and it shares
that dependency with D3-a — a domain — which is already deferred. Holding the
Phase 2.7 release behind it would delay verified, finished work for an
infrastructure decision that has no bearing on the loopback deployment. Mailpit
is the correct transport for that release, and the Tier-1 email smoke tests are
executable today.

That suggests the gate order I flagged in the D3-c transition report:

```
D5 (rollback rehearsal)  →  loopback deployment  →  D4 + D3-a/D3-b as one
                                                    network-exposure workstream
```

Your call. If you prefer D4 first, it simply waits on D4-a and D4-b.

Nothing was deployed, no migration was run, Mailpit was not replaced, no provider
was registered or selected, no credential was created or printed, no test data
was touched, no code changed, and nothing was pushed. Production remains at
migration 30, loopback-only.

Stopping here. D5 remains pending your authorization.

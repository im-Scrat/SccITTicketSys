---
title: "Credential Readiness — WP-2.6b Smoke-Test Accounts"
project: "SccIT — School IT Service Management System"
work_package: "WP-2.6b smoke-test preparation (credentials only)"
stage: "Credentials prepared and verified — NOT deployed, NOT migrated"
date: "2026-08-30"
author: "Engineering"
status: "Complete — WP-2.6b production deployment remains NOT AUTHORIZED"
baseline: "SRS / SDD / SPMP Version 1.0 (frozen by Client instruction — unchanged)"
---

# Credential Readiness — WP-2.6b Smoke-Test Accounts

Only what Decision authorized: temporary credentials for the three
verification-only accounts. **No passwords, tokens or secrets appear anywhere in
this report.**

---

## Where the credentials are

They were generated **inside** the production container, written to a file
there, copied to the host, and the container copy deleted. **They were never
printed to a console, never passed as a command argument, and never appeared in
any transcript.**

```
C:/Users/admin/AppData/Local/Temp/claude/c--Users-admin-Desktop-SccITTicketSys/
  1866f1ec-0f53-4284-94c2-79026361f5c6/scratchpad/wp26b-smoketest-credentials.txt
```

404 bytes, 8 lines, one line per account. The file is **outside the git
repository**, so it cannot be committed by accident.

**Two things to note.** That directory is a session-scoped temporary location —
move the file somewhere durable if you want it after this session. And these are
plaintext credentials for three live accounts on the production-target stack:
treat the file accordingly, and let the cleanup stage remove the accounts it
describes.

---

## 1. The three accounts are ready for authenticated testing

| Account | Role | Status | Credential accepted | Forced reset | Locked |
|---|---|---|---|---|---|
| `verify.admin@sccit.local` | administrator | active | **yes** | no | no |
| `verify.tech@sccit.local` | technician | active | **yes** | no | no |
| `verify.teacher@sccit.local` | teacher | active | **yes** | no | no |

**Mechanism used:** the application's own
`App\Domains\Identity\Actions\ChangeUserPassword` — the supported password-change
path (SRS FR-AUTH-011). It hashes through the model's `hashed` cast, stamps
`password_changed_at`, clears `force_password_reset`, and writes an
`ActivityAction::PasswordChanged` audit entry. **No new mechanism was invented
and no security control was bypassed.**

**Policy compliance:** each password is cryptographically random (`random_int`),
**16+ characters**, and contains all four character classes. The project's
`PasswordPolicy` (FR-AUTH-003) requires the configured minimum length, at least
3 of 4 classes, and rejection of common passwords — all satisfied with margin,
and a random string of this length cannot appear in the bundled common-password
list.

### What "ready" was actually verified — and what it was not

Each credential was checked with `Auth::validate()`, **the same credential
provider `/api/login` uses**, and each account was confirmed `active`, unlocked,
and not flagged for a forced password reset — the three conditions the
`EnsureAccountIsActive` and `password.current` middleware enforce behind the
login.

**No end-to-end HTTP sign-in was performed.** That would create session and
login-history rows in production, and the smoke test itself is not authorized
yet. The verification above covers credential correctness and account state; the
first real sign-in will happen during the authorized smoke test.

### An alternative, if you would rather I never knew them

`AdminSendPasswordReset` exists and would email a reset link to Mailpit
(`:8026`), letting you set the passwords yourself. I did not use it because you
asked me to prepare the credentials *and verify they are usable*, which that flow
does not allow. Say the word and I will re-issue them that way instead.

---

## 2. Roles and authorization were not changed

Captured before and after each reset:

| Account | Role before → after | Status before → after |
|---|---|---|
| `verify.admin@sccit.local` | administrator → **administrator** | active → **active** |
| `verify.tech@sccit.local` | technician → **technician** | active → **active** |
| `verify.teacher@sccit.local` | teacher → **teacher** | active → **active** |

No role, permission, per-user override or policy was touched. **No permission was
weakened to make anything pass.**

---

## 3. No other account was modified

`password_changed_at` across all six production users:

| Account | Password changed |
|---|---|
| `admin@sccit.local` | **never** — untouched |
| `pro@sccpag.com` | **never** — untouched |
| `testingi@sccedu.com` | **never** — untouched |
| `verify.admin@sccit.local` | 2026-08-30 11:26:53 |
| `verify.tech@sccit.local` | 2026-08-30 11:26:53 |
| `verify.teacher@sccit.local` | 2026-08-30 11:26:53 |

Exactly the three authorized accounts, and no others.

---

## 4. No code, schema, configuration or documentation changed

| Item | State |
|---|---|
| Application code | **unchanged** — `git status` clean of code changes |
| Database schema | **unchanged** — no DDL executed |
| Configuration | **unchanged** — `.env.production`, `compose.prod.yaml`, Dockerfiles all untouched |
| Network exposure | **unchanged** — both published ports still bound to `127.0.0.1` |
| SRS / SDD / SPMP | **unchanged, Version 1.0** — `git status docs/` reports 0 changed files |

Temporary scripts were written to `/tmp` inside the container and on the host,
executed, and **deleted**. Nothing was left in the repository.

---

## 5. No migrations were run

Production migration count: **28** — unchanged. Migrations 28 → 30 remain
pending and **were not run**.

---

## 6. No deployment occurred

The running containers still use image `sha256:81180836ee55…` — the
**pre-WP-2.6b** build (8 domains, no `WorkSupport`, 28 migration files).

The verified release images remain built and undeployed:
`sccit/app:prod de13d793201d`, `sccit/web:prod b8bd04e3c427`.

---

## 7. No verification data was deleted

| Data | State |
|---|---|
| `ZZ-VERIFY` dataset | intact — 2 PC units, 1 asset, 1 spec, room/floor/building, catalog rows |
| Verification accounts | intact — all six users present, none deleted |
| Verification tickets | intact — `TKT-2026-00001`, `TKT-2026-00002` |
| QR records | **0** — none created, none revoked (generation remains a smoke-test step) |
| Development QR records | untouched |

Counts confirmed after the work: `users=6 tickets=2 pc_units=2 assets=1
qr_codes=0`.

---

## 8. No commit was created and nothing was pushed

`HEAD` is still **`2df17db`**. No commit, nothing staged, no upstream configured,
nothing pushed. The only untracked files are the reports in `projectreports/`.

---

## 9. Deployment authorization boundary

> ### **WP-2.6b production deployment remains NOT AUTHORIZED.**

Not deployed · not migrated · no code, schema, config or document change · no
network exposure · no cleanup · no commit · no push · Phase 2.7 not started.

---

## 10. The exact next action required before deployment authorization

**Nothing further is required of me. The next action is yours: authorize
deployment.**

Every prerequisite identified in the readiness checkpoint is now closed:

| Prerequisite | State |
|---|---|
| Migrations 28 → 30 analysed and judged safe | Done — both additive, dependencies present, rollbacks working |
| Two "schema inconsistencies" | Resolved — neither exists |
| Images synchronised and verified | Done — byte-identical to `HEAD`, both corrections present |
| Environment classification | Decided — production-target, loopback-only for this stage |
| Verification dataset | Created — `ZZ-VERIFY`, 9 rows |
| **Smoke-test credentials** | **Done — this report** |
| Cleanup ordering | Decided — after a passed smoke test |

**When you authorize deployment, the sequence is the one already documented** in
the Deployment-Readiness Checkpoint §7, and two things in it deserve repeating
because they are easy to lose at the moment of execution:

1. **Record the current image digest `sha256:81180836ee55…` before deploying.**
   The rollback plan is tag-based and has nothing to point at without it.
2. **Every command must carry `-p sccit_prod -f compose.prod.yaml`.** No script in
   `scripts/` targets production — `scripts/backup.sh` in particular would back up
   the **development** database. The backup step must be run explicitly as written
   in that report.

Deploying will ship the **entire WP-2.6b release** plus both corrections, not two
small fixes — the running stack predates all of it.

**Stopping here and awaiting your explicit deployment authorization.**

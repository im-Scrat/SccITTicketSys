---
title: "SESSION 02 — WP-A LARAVEL 13 REVERB INFRASTRUCTURE"
project: "SccIT — School IT Service Management System"
work_package: "WP-A — Reverb real-time infrastructure (Phase 2.7 + 2.8 V1 integrated release)"
stage: "WP-A CHECKPOINT — implemented, verified, committed; STOPPED before WP-B"
date: "2026-09-28"
author: "Engineering"
status: "Complete — commit 742ded5 (not pushed); two-browser proof passed; full gate green; production not rebuilt or deployed"
baseline: "HEAD 742ded5 · parent 281356c (OD-1) · branch recovery/phase-2.7-restored-baseline · recovery baseline c9ab8a4"
---

# SESSION 02 — WP-A LARAVEL 13 REVERB INFRASTRUCTURE

**Purpose.** Implement and verify WP-A only: Laravel Reverb installed through the
Laravel 13 installer, a same-origin WebSocket path, authenticated private-channel
authorization, an Echo client, and proof in two authenticated browsers. No floor-plan,
AI, maintenance, RAG or assistant behaviour was implemented.

**Evidence labels.** Every claim carries one of: *inspected · implemented · tested ·
passed · failed · blocked · not yet verified*. Runtime claims come from runtime
evidence; browser claims from browser evidence. Nothing on production was verified at
runtime, because WP-O is not authorized.

---

## 0. Findings the owner must see first

| # | Finding | Status |
|---|---|---|
| **N-1** | **The dev PHP tier had been down since OD-1.** php-fpm workers were segfaulting (SIGSEGV, 592 crashes). Every page and API call through `:8080` returned 502. The first failure was at 18:33:32 UTC (02:33:32 +08), while OD-1's `composer update` was rewriting `vendor/` under the running FPM, which has OPcache + tracing JIT enabled. OD-1 was committed at 02:47:56 +08. SESSION 01's gates all run through CLI (`opcache.enable_cli = 0`), so they could not see it. **The SESSION 01 report's "no compatibility regressions" was true of the gate but did not cover the running dev stack.** | inspected → fixed by a non-destructive `docker compose restart app` → **passed**: 0 segfaults after restart, 15/15 requests 200 |
| **N-2** | **The frontend has 10 pre-existing npm advisories** (3 moderate, 7 high) in build/test tooling: vitest/mocker, browserslist, postcss, nanoid, brace-expansion, baseline-browser-mapping. OD-1 covered Composer only. The HEAD lockfile and the WP-A lockfile audit identically, and no advisory names the three packages WP-A added. | inspected — **pre-existing, not WP-A; owner decision needed** |
| **N-3** | **`compose.prod.yaml` cannot be parsed as-is**, because `backend/.env.production` does not exist and Compose resolves `env_file` at parse time. The running prod containers were created before that file went missing. Recreating any prod container will fail until the file exists. This is a WP-O prerequisite adjacent to F-3. | inspected — **pre-existing, blocks WP-O** |

---

## 1. Pre-flight — passed

| Check | Evidence | Status |
|---|---|---|
| HEAD at session start | `281356c` (OD-1) | inspected |
| Pre-existing working tree | 21 modified, +220/−70, 0 staged. Diff SHA-256 `79c8b2b8…c1a1a47a1c911dea7024` recorded | inspected |
| Same tree after installer, after rework, after commit | identical hash at every point | **passed** |
| Pre-existing files in the WP-A commit | **0** | **passed** |

---

## 2. Actual Laravel 13.17 installer behaviour — inspected

Read from `vendor/.../BroadcastingInstallCommand.php` and Reverb's `InstallCommand.php`
before running, then confirmed against the real result. The command was
`php artisan install:broadcasting --reverb --without-node --no-interaction`.
`--without-node` was used because the installer targets `backend/package.json` (a
vestigial Laravel skeleton), not the React SPA in `frontend/`.

| Installer action | What it actually did | Disposition |
|---|---|---|
| `config:publish broadcasting` | created `config/broadcasting.php` | **kept** |
| channels file | created `routes/channels.php` with `App.Models.User.{id}` | **reworked**: numeric id in a client-visible channel name (NFR-SEC-001), and unused. No channels ship yet. |
| `uncommentChannelsRoutesFile()` | inserted `channels:` into `withRouting()`. In L13.17 this calls `withBroadcasting()` with no attributes, so `Broadcast::routes()` defaults to the **`web`** group | **reworked** → `withBroadcasting()` with `['api','auth:sanctum','active',AuthenticateSession::class,'password.current']` |
| `BROADCAST_CONNECTION` | wrote `reverb` to `backend/.env` | kept |
| `composer require laravel/reverb:^1.0` | **v1.12.0 + 12 dependencies** (13 installs, 0 updates, 0 removals); in-command `composer audit` clean | **kept** |
| `reverb:install` → env | random `REVERB_APP_ID/KEY/SECRET`; **hard-coded** `REVERB_HOST="localhost"`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`; `VITE_REVERB_*` ×4 | id/key/secret kept (never printed). **Host/port reworked** → `reverb` / `6001`. `VITE_REVERB_*` removed. |
| `reverb:install` → config | created `config/reverb.php` with `allowed_origins => ['*']` | kept; **`allowed_origins` reworked** → `REVERB_ALLOWED_ORIGINS`, fail-closed |
| Echo scaffold | not React (per `backend/package.json`) → created `backend/resources/js/echo.js` and appended `import './echo'` to `bootstrap.js` | **reverted**: dead code; the SPA does not use backend Vite |
| Node dependencies | skipped by `--without-node` | installed manually in `frontend/` (§6) |
| Docker / nginx | **none** — the installer does not touch infrastructure | written by hand (§4) |

**Laravel 13 differences observed (not Laravel 12 patterns):** `Broadcast::routes()`
registers `GET|POST /broadcasting/auth` and strips L13's `PreventRequestForgery` (the
renamed CSRF middleware). CSRF is still enforced here by Sanctum's stateful pipeline
inside the `api` group.

---

## 3. Changed files — 20, commit `742ded5`

| File | Change | Status |
|---|---|---|
| `backend/composer.json` / `composer.lock` | `laravel/reverb ^1.0`; lock **13 added / 0 changed / 0 removed** (OD-1 versions untouched) | implemented |
| `backend/bootstrap/app.php` | `withBroadcasting()` behind the API feature-route floor | implemented |
| `backend/config/broadcasting.php` | published, unmodified | implemented |
| `backend/config/reverb.php` | published; `allowed_origins` env-driven, fail-closed | implemented |
| `backend/routes/channels.php` | policy docblock only; no channels | implemented |
| `backend/routes/api.php` | `GET /api/broadcasting/config` | implemented |
| `backend/app/Http/Controllers/BroadcastingConfigController.php` | returns the public key only; 503 if not configured | implemented |
| `backend/.env.example` / `.env.production.example` | Reverb block, **blank** credential placeholders | implemented |
| `compose.yaml` / `compose.prod.yaml` | `reverb` / `reverb_prod` services: `expose: 6001`, **no `ports:`**, port healthcheck | implemented |
| `docker/nginx/default.conf` / `prod.conf` | `/broadcasting` → Laravel; `/reverb/app/` → WebSocket proxy | implemented |
| `frontend/package.json` / `package-lock.json` | `laravel-echo ^2.5.0`, `pusher-js ^8.6.0`; lock **3 added** (incl. `tweetnacl`) | implemented |
| `frontend/src/services/echo.ts` | Echo client | implemented |
| `frontend/vite.config.ts` | `realtime` manual chunk | implemented |
| `backend/tests/Feature/Broadcasting/BroadcastingAuthorizationTest.php` | 14 tests | implemented |
| `frontend/src/services/echo.test.ts` | 9 tests | implemented |

**Also changed, not committed (gitignored, runtime-only):** `backend/.env`, where the
installer's host/port was changed to `reverb`/`6001`, `REVERB_ALLOWED_ORIGINS=localhost`
was added and `VITE_REVERB_*` removed. Secret values were never printed.

---

## 4. Architecture and design decisions — implemented

**Topology.** Browser → same-origin `/broadcasting/auth` → Laravel.
Browser → same-origin `/reverb/app/{key}` → nginx → `reverb:6001` (internal).
Laravel (app + queue) → `reverb:6001/apps/…` directly over the compose network.

| Decision | Why |
|---|---|
| Internal port **6001**, not 8080 | 8080 is the host-facing nginx port; owner instruction. Fixed in compose beside the nginx upstream that must match it. |
| Only `/reverb/app/` proxied | Reverb's HTTP publishing API (`/apps/…`) stays unreachable from browsers. |
| Variable upstream + explicit `rewrite` | Same recreate-safe DNS pattern as `$fpm_upstream`. A variable `proxy_pass` does no prefix substitution. |
| `proxy_read_timeout 300s` | nginx's 60 s default would cut idle sockets. |
| **Key delivered at runtime** (`GET /api/broadcasting/config`) instead of `VITE_REVERB_APP_KEY` | The SPA builds in its own container from `frontend/`, which never sees `backend/.env`. The prod web image must not carry per-deployment values. Runtime delivery keeps backend env as the single source of truth and lets production rotate the key without an image rebuild. **Deviation from the installer's pattern; should be recorded as a DD at doc reconciliation.** |
| Channel auth through the app's axios client | Carries the Sanctum session + `X-XSRF-TOKEN` and inherits the 419 retry. pusher-js's own authorizer sends neither. |
| Socket torn down when the principal is cleared or changes | Pusher channels are authorized once, at subscribe time. A surviving socket would leak the previous user's private channels to the next sign-in in the same tab. |
| `realtime` chunk, not `vendor` | The plan said "add to the manualChunks allowlist". `vendor` is always loaded (the public site included), so Echo got its own named, long-cacheable manual chunk loaded only on first use. |

---

## 5. Package versions — inspected

| Package | Version | Where |
|---|---|---|
| `laravel/reverb` | v1.12.0 | Composer |
| `pusher/pusher-php-server` | 7.3.0 | Composer (Reverb dependency) |
| ReactPHP stack, `ratchet/rfc6455`, `clue/redis-*`, `evenement` | 11 packages | Composer (Reverb dependencies) |
| `laravel-echo` | 2.5.0 (npm latest at install) | npm, `dependencies` |
| `pusher-js` | 8.6.0 (npm latest at install) | npm, `dependencies` |
| `tweetnacl` | via pusher-js | npm lock |

**Deviation:** the installer would use `--save-dev`. These were placed in
`dependencies` to match this project's convention for bundled runtime libraries.

**F-1 protection (not remediation).** `npm ls` showed **11 extraneous packages**
(`@playwright/test`, `playwright`, `playwright-core`, `@axe-core/playwright`,
`axe-core`, and 6 wasm/tslib leftovers), which any ordinary `npm install` prunes.
Procedure used:

1. `npm install … --package-lock-only`, which changed the manifest only.
2. `npm install --no-save` of the 11 at exact versions.

Result: `node_modules` = before **+ laravel-echo + pusher-js**; manifest hash unchanged
by step 2; Playwright 1.62.1 still runs. F-1 itself is untouched and still open.

---

## 6. Runtime verification (dev `:8080`) — tested / passed

### 6.1 Routing and broadcasting auth

`route:list`: `GET|POST|HEAD broadcasting/auth` and `GET api/broadcasting/config`, both
behind `api → Authenticate:sanctum → EnsureAccountIsActive → AuthenticateSession →
EnsurePasswordIsCurrent`.

| Case | Method | Status | Content-Type | Body | Status |
|---|---|---|---|---|---|
| No session, no first-party origin | POST | **401** | application/json | "Unauthenticated." | passed |
| Guest session + valid XSRF | POST | **401** | application/json | "Unauthenticated." | passed |
| First-party origin, no XSRF token | POST | **419** | application/json | "CSRF token mismatch." (Sanctum CSRF runs before auth) | passed |
| Administrator → admin-only channel | POST | **200** | application/json | `{"auth":"<key>:<64-hex HMAC>"}` | passed |
| Teacher | POST | **403** | application/json | AccessDenied | passed |
| Technician | POST | **403** | application/json | AccessDenied | passed |
| `/api/broadcasting/config`, guest | GET | **401** | application/json | — | passed |
| `/api/broadcasting/config`, any role | GET | **200** | application/json | `{"key":…}` only (20 chars) | passed |

`/broadcasting/auth` now reaches Laravel and answers JSON. Before this change, both
stacks would have served the SPA's `index.html` for it.

### 6.2 WebSocket and Reverb service

| Check | Evidence | Status |
|---|---|---|
| Reverb running | `INFO Starting server on 0.0.0.0:6001 (reverb)`; healthcheck `healthy` | passed |
| No host port | `docker port sccit_reverb` → empty; `curl localhost:6001` → unreachable | passed |
| Upgrade through nginx | `101 Switching Protocols`, correct RFC 6455 `Sec-WebSocket-Accept`, then `pusher:connection_established` (`activity_timeout: 30`) | passed |
| `/reverb` prefix rewrite | Reverb served `/app/{key}` at its root | passed |
| Origin allow-list | `Origin: http://evil.example` → `pusher:error 4009 "Origin not allowed"` | passed |
| Reverb HTTP API not public | `/reverb/apps/<id>/channels` via `:8080` → SPA `index.html`, not Reverb | passed |
| Internal publish path | `app` container → `reverb:6001/apps/…` → Reverb's own 401 (unsigned) | passed |

### 6.3 Two-browser proof

Four **independent Chromium processes**, each signing in through the real `/sign-in`
form and subscribing through the application's own `src/services/echo.ts` (same
`api`/`queryClient` instances as the app). The event was a *queued* `ShouldBroadcast`,
so the proof travelled the real path: app → Redis → queue worker → Reverb → sockets.

| Session | Role | `/broadcasting/auth` | Subscription | Received nonce | Status |
|---|---|---|---|---|---|
| **A** (publisher) | administrator | 200 JSON | `subscription_succeeded` | yes | passed |
| **B** (receiver) | administrator | 200 JSON | `subscription_succeeded` | **yes, 2.5 s after publish, 0 navigations, page marker intact** | **passed** |
| **C** (negative) | teacher | **403 JSON** | `AuthError` | **no** | passed |
| **D** (CSP) | administrator | 200 JSON | `subscription_succeeded` | yes, under enforced prod `connect-src` | passed |

Frame sequence captured in A, B and D:

1. `← pusher:connection_established`
2. `→ pusher:subscribe {auth:<key>:<hmac>, channel:private-wp-a.verification}`
3. `← pusher_internal:subscription_succeeded`
4. `← wpa.verification.ping {nonce, sent_at}`

WebSocket URL: `ws://localhost:8080/reverb/app/<key>?protocol=7&client=js&version=8.6.0`.
The only other 401 in any session was `GET /api/user` on `/sign-in` (the SPA's normal
pre-login probe). Evidence JSON (key redacted) is kept in the session scratchpad.

---

## 7. CSP — tested; no change required; production CSP left unchanged

| Step | Result |
|---|---|
| Dev CSP | `connect-src 'self' ws: wss:` — never in question |
| Question | Does production's `connect-src 'self'` permit the same-origin `ws://…/reverb/…`? |
| First attempt (override via CDP `Fetch.continueResponse`) | **Rejected as invalid evidence.** A cross-origin control socket was *not* blocked, which proves the injected header was delivered but **not enforced**. |
| Valid method (CDP `Fetch.fulfillRequest`) | Enforced — the control `ws://example.invalid` was blocked: *"violates … connect-src 'self'"* |
| Harness artifact | Fulfilled documents lose loopback attribution, so Local Network Access blocked *all* localhost sockets (not CSP). Disabled for session D only (`--disable-features=LocalNetworkAccessChecks`). |
| **Result** | Under enforced `connect-src 'self'` the same-origin Reverb socket opened, subscribed and received the event. **The only violation was the deliberate cross-origin control.** |

**Conclusion:** no widening needed. `docker/nginx/security-headers.prod.conf` was not
modified. Runtime confirmation on `:8081` is **not yet verified** and belongs to WP-O.

---

## 8. Production-side changes — implemented; runtime **not yet verified** (WP-O)

| Check | Result | Status |
|---|---|---|
| `nginx -t` on `prod.conf` (disposable container, `--network none`) | syntax ok, test successful | passed |
| `compose.prod.yaml` as-is | cannot parse: `backend/.env.production` absent (N-3) | **blocked — pre-existing** |
| Schema via scratchpad copy pointing at `.env.production.example` | **valid**; `reverb_prod` parsed with `ports: null`, `expose: ["6001"]` | passed |
| `route:cache` (production boot path) | compiles with the new routes | passed |
| Prod image rebuilt / deployed | **no** | not yet verified (WP-O) |
| Prod `:8081` health | 200 throughout (read-only probe) | inspected |

---

## 9. Automated gate — final tree

| Gate | Result | Status |
|---|---|---|
| `pest` (serial) | **957 passed, 0 failed** (3749 assertions, 508 s). Prior 943 + 14 new | passed |
| `pint --test` | **PASS, 671 files** (one issue in the new test file found and fixed in-session) | passed |
| `phpstan` (Larastan 6) | **No errors** | passed |
| `tsc -b` | exit 0 | passed |
| `npm run lint` | exit 0 — 0 errors, 1 warning in `e2e/specs/a11y.spec.ts` (untouched; pre-existing) | passed |
| `format:check` | 3 files flagged, all untracked generated artifacts (`e2e/.artifacts/*.json`, `test-results/.last-run.json`) that pre-date this session. All WP-A files pass | **failed — pre-existing, not WP-A** |
| `vitest` | **322 passed / 36 files**, incl. 9 new | passed |
| Mutation check on teardown | disabling it fails **4** teardown tests; file restored byte-identical | passed |
| `npm run build` | exit 0 | passed |
| Bundle secret scan | Reverb key, secret and app id: **0** occurrences in `dist/`; no `VITE_REVERB` | passed |
| `realtime` chunk | not emitted — nothing imports `echo.ts` until WP-E | **not yet verified** |
| `scripts/e2e.sh --project e2e` | **35 passed** (24.3 min) | passed |
| `scripts/e2e.sh --project a11y` | **28 passed** (9.0 min) | passed |
| `composer validate` / `composer audit` | valid / no advisories | passed |

E2E/a11y ran on the working tree, which includes the uncommitted harness repair and the
preserved-but-undeclared Playwright packages. On a clean checkout both would be
**blocked — pre-existing F-1**.

---

## 10. Throwaway verification mechanism — removed

Implemented, used and deleted in-session: `wp-a.verification` channel, the
`WpaVerificationPing` queued event, `WpaVerificationPingController` and
`POST /api/_wpa/verification-ping`. `app/Events/` did not exist at HEAD and was
removed. A grep for residue found none; `route:list` shows 0 `_wpa` routes. The proof
scripts lived only in the scratchpad and container `/tmp`, both cleaned. The permanent
tests register their own channel in-test.

---

## 11. Git — implemented

| Item | Value |
|---|---|
| Commit | **`742ded58d0100b92419d9d83cf84cdc4f61dc6a6`** (`742ded5`) |
| Subject | `feat(realtime): WP-A — Laravel Reverb infrastructure (same-origin, private channels)` |
| Files | 20 (13 modified, 7 added), +1932 / −16 |
| Staging | explicit paths only; verified 20 files, 0 pre-existing, 0 reports/artifacts |
| Pushed | **no** — `origin` still at `d05fd60` |
| Reset / rebase / amend / stash / clean | none |
| Recovery baseline `c9ab8a4` | still an ancestor |
| Indexes | Graphify rebuilt; codebase-memory re-indexed (`full`) |

---

## 12. Unresolved issues

| Item | Owner action |
|---|---|
| **N-1** OD-1 crashed dev FPM; fixed by a restart | Any future in-place `vendor/` change needs `docker compose restart app queue` before trusting the dev stack |
| **N-2** 10 pre-existing npm advisories | Decide whether an npm counterpart to OD-1 is wanted |
| **N-3** `backend/.env.production` absent | Must exist before WP-O. It will also need the `REVERB_*` block |
| **F-1** Playwright/axe undeclared | Still open. Preserved, not fixed |
| **F-2** root `.env.production` not gitignored | Still open (before WP-H) |
| **F-3** no production backup snapshot | Still open (WP-O) |
| Production Reverb runtime, `:8081` CSP, two-Admin prod test | Not yet verified — WP-O |
| `realtime` chunk | Not yet verified until WP-E imports `getEcho()` |
| Docs | Record the runtime-key DD, the same-origin Reverb proxy DD and the N-1 lesson at documentation reconciliation |

---

## 13. Stop

WP-A is complete. **Stopped before WP-B.** No floor-plan, AI, maintenance, RAG,
assistant, WP-N, WP-O, F-1 or F-2 work was started.

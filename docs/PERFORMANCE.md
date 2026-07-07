# SccIT — Production Performance Optimization Pass

> **Scope & guarantees.** This pass was performed *before* Phase 2.4 as a dedicated
> performance effort. **No features, business logic, UI, or architecture were
> changed.** Every change is additive and reversible. The development stack
> (`compose.yaml`, `http://localhost:8080`, Vite HMR + bind mounts) is **unchanged**
> and remains the daily driver. A new, fully isolated production-like stack
> (`compose.prod.yaml`, `http://localhost:8081`) was added to serve the app the way
> production would, and to benchmark dev vs prod.

---

## 1. Performance bottlenecks found

Profiling the running stack made one thing clear: **the application code is already
well-built; the measured slowness was environment/configuration, not code.**

| # | Bottleneck | Evidence | Nature |
|---|---|---|---|
| B1 | **Windows bind-mount `stat()` storm** | Walking `vendor/` (11,279 files) inside the container took **5.2 s** (~0.46 ms/file). Laravel loads 1,000+ files per request. | Dev-only (bind mount) |
| B2 | **OPcache revalidating every request** | `opcache.validate_timestamps=1`, `revalidate_freq=0` → PHP `stat()`s every cached file every request, over the slow mount (B1). | Config |
| B3 | **No Laravel config/route/event cache** | `bootstrap/cache/` had only `packages.php`/`services.php`; every request re-parsed all config files. | Config |
| B4 | **`APP_DEBUG=true`, `APP_ENV=local`** | Full error/stack machinery on the hot path. | Config |
| B5 | **No production serving path** | Nginx proxied `/` to the **Vite dev server** (unbundled, HMR). The `dist/` build existed but was never served; **no gzip, no static cache headers**. | Serving |
| B6 | **Route closures blocked `route:cache`** | `routes/web.php` `/` and `routes/api.php` `/health` used closures → `php artisan route:cache` fails to serialize. | Code (blocker) |
| B7 | **No vendor bundle split** | Vite emitted a single 91 KB-gzip `index` chunk fusing framework + app code → any app change busted the whole chunk in browser caches. | Build |

**Measured "before" (dev, from the Windows host):**

| Endpoint | Time |
|---|---|
| `GET /up` (trivial health) | 2.1 – 6.0 s |
| `GET /api/health` | 2.4 – 6.3 s |
| `POST /api/login` | **13.2 s** |
| `GET /api/user` | 5.8 – 6.8 s |
| `GET /api/admin/users` | 3.0 – 3.8 s |
| `GET /api/admin/users/dashboard` | 2.5 – 2.7 s |

That `/api/user` (trivial) was *slower* than `/api/admin/users` (more DB work) proves
the cost was framework boot + filesystem, not query complexity.

**Not bottlenecks (verified healthy):** DB schema is well-indexed (users:
`role_id`, `status`, unique `email`/`uuid`/`employee_number`; proper composite pivot
PKs). `PermissionResolver` is Redis-cached with explicit invalidation. `AuditLogger`
does simple inserts. `UserDirectoryQuery` paginates in-DB with eager-loaded `role`
(no N+1). The list resource does no per-row permission resolution. Route-level code
splitting (`React.lazy`) was already in place. TanStack Query is sensibly configured.

---

## 2. Every optimization performed

**Backend code (shared; responses byte-identical):**
- Converted the two route closures to invokable controllers
  (`app/Http/Controllers/HealthController.php`, `WelcomeController.php`) so the HTTP
  route table can be serialized by `route:cache`. Fixes **B6**.

**Production PHP image (`docker/php/Dockerfile` *production* stage — dev stage untouched):**
- `docker/php/php.prod.ini` (loaded only in the prod stage): OPcache
  `validate_timestamps=0`, `memory_consumption=256`, `interned_strings_buffer=32`,
  tracing JIT (128 MB), realpath cache 4 MB, `display_errors=Off`. Fixes **B2**.
- `docker/php/prod-entrypoint.sh`: on boot runs `optimize:clear` then
  `config:cache` + `route:cache` + `event:cache` + `storage:link`. Fixes **B3**.
- `composer install --no-dev --optimize-autoloader` (optimized classmap; *not*
  authoritative, to avoid runtime-class regressions).
- `.env.production`: `APP_ENV=production`, `APP_DEBUG=false`. Fixes **B4**.

**Production web image (`docker/nginx/prod.conf` + `Dockerfile.prod` → `sccit/web:prod`):**
- Serves the static Vite `dist/` with SPA fallback (`try_files … /index.html`). Fixes **B5**.
- **gzip** on text assets (`gzip_comp_level 6`).
- **Immutable cache headers**: `/assets/*` (content-hashed) →
  `Cache-Control: public, max-age=31536000, immutable`; `index.html` → `no-cache`
  so deploys apply instantly; `/storage/*` → 7-day public cache.
- Decoupled fastcgi reverse-proxy to `app_prod:9000` (fixed `SCRIPT_FILENAME`, since
  Nginx holds no PHP source).

**Frontend build (`vite.config.ts` — build-only; HMR/dev unaffected):**
- `build.rollupOptions.output.manualChunks`: splits the always-loaded, rarely-changing
  core (React, react-dom, react-router, TanStack Query, zustand, axios) into a stable
  `vendor` chunk. Deliberately narrow — `lucide-react` (per-icon chunks) and `zod`
  (own chunk) keep their existing splitting. Fixes **B7**.

**Orchestration / DX:**
- `compose.prod.yaml`: standalone, isolated production stack on **:8081**.
- `backend/.env.production.example` (tracked template) + `.env.production` (real, gitignored).
- `Makefile` `prod-*` targets. `.dockerignore` excludes `**/public/storage`
  (absolute symlink can't enter the build context; recreated at boot).

---

## 3. Before vs. after metrics

Every request through the Windows host pays a **constant ~210 ms Docker Desktop
loopback-proxy tax** that is unrelated to the app (both stacks pay it equally). To
isolate true application latency, the table below reports **internal** latency
(measured from inside the Docker network, no Windows proxy) — which is what a real
Linux production host delivers end-to-end.

| Endpoint | DEV (internal) | PROD (internal) | Speedup |
|---|---:|---:|---:|
| `GET /up` | 1,926 ms | **5.9 ms** | **~325×** |
| `GET /api/health` (DB + Redis ping) | 16,670 ms | **16.9 ms** | **~985×** |

End-to-end from the Windows host (includes the ~210 ms proxy tax on both):

| Endpoint | DEV | PROD | Speedup |
|---|---:|---:|---:|
| `GET /up` | ~2.5 s (median) | **0.217 s** | ~11× |
| `GET /api/health` | ~6.4 s | **0.231 s** | ~28× |
| `POST /api/login` | 13.2 s | **0.517 s** | ~26× |
| `GET /api/user` | ~6.3 s | **0.232 s** | ~27× |
| `GET /api/admin/users` | ~3.4 s | **0.243 s** | ~14× |
| `GET /api/admin/users/dashboard` | ~2.6 s | **0.238 s** | ~11× |

The residual per-request delta between prod endpoints (`/up` 5.9 ms vs
`/api/admin/users/dashboard` ~24 ms internal) *is* the real application + DB work — a
few ms to low-tens of ms.

---

## 4. Bundle size comparison

Total transfer is essentially flat (splitting is for cache efficiency, not size);
the win is **cache isolation**.

| | Before (single fused chunk) | After (`manualChunks`) |
|---|---|---|
| JS + CSS total | 619 KB raw / **200 KB gzip** | 606 KB raw / **195 KB gzip** |
| Largest always-loaded chunk | `index` **91 KB gzip** (framework **+** app fused) | `vendor` **98 KB gzip** (framework only, stable) |
| App entry chunk | (fused into the 91 KB) | `index` **8.4 KB gzip** |
| `zod` (`schemas`) | 27.5 KB gzip (separate) | 27.9 KB gzip (separate, unchanged) |
| Route chunks (per page) | present (`React.lazy`) | present (unchanged) |
| Fonts | 12 woff2 subsets, 303 KB on disk | unchanged (see note) |

**Cache-isolation effect:** before, any app-code change invalidated the entire 91 KB
framework+app blob in users' caches. After, an app deploy invalidates only the
~8.4 KB entry + changed route chunks; the 98 KB `vendor` chunk stays cached until a
dependency actually changes. On the wire, assets are now **gzip-compressed** and
carry `Cache-Control: immutable`.

**Fonts (investigated, intentionally left as-is):** Inter/JetBrains Mono are
self-hosted variable fonts with `unicode-range` subsetting — browsers already
download **only the latin subset** for the English UI; the other 11 subsets ship on
disk but are never fetched by users. Forcing a narrower subset risks changing glyph
rendering for no user-facing transfer benefit, so fonts were not touched (they now
simply gain immutable cache headers).

---

## 5. Database improvements

**No schema or query changes were required** — the data layer is already sound:

- Indexes verified present on every filtered/sorted/joined column in the live
  surface (`users.role_id`, `users.status`, unique `email`/`uuid`/`employee_number`,
  `permissions.module`, composite pivot PKs on `role_permissions`/`user_permissions`
  plus `permission_id`/`granted_by`).
- Directory listing paginates in the database (`UserDirectoryQuery`) with
  `->with('role')` — no N+1. The dashboard uses a fixed set of aggregate queries
  (`UserMetrics`) rather than materializing collections.
- The real DB win is indirect: with OPcache + config/route cache removing framework
  overhead, and the DB reached over the container network (never the bind mount),
  query time is now the dominant term (single-digit ms) instead of being buried under
  seconds of boot cost.

Deferred (future scaling, not current bottlenecks): a trigram/GIN index for the
`ILIKE '%term%'` directory search, and an index on `users.last_login_at` — both only
matter at much larger row counts. See §8.

---

## 6. API response improvements

Covered quantitatively in §3. Qualitatively, the same Laravel code now:
- boots from **cached config/routes/events** (no per-request config parsing),
- runs on **OPcache with timestamp validation off** (no per-file `stat()`),
- executes from a **baked image** (no bind-mount filesystem),

turning multi-second responses into single-digit-to-low-tens-of-ms responses, with
**zero change to controllers, services, or responses** (verified — see §"Verification").

---

## 7. Remaining bottlenecks

1. **Docker Desktop Windows loopback proxy (~210 ms/request).** Dominates prod
   host-side latency; a Linux production host has no such proxy (internal numbers in
   §3 are the true prod latency). Not fixable from the app.
2. **Dev inner-loop remains slow by design.** The bind mount + revalidating OPcache
   that make HMR/live-edit possible are exactly what make dev requests slow. This is
   an accepted dev/prod trade-off; the prod stack is the fast path.
3. **`bcrypt` rounds = 12** (~250 ms) is the floor for login CPU. Correct for
   security; not reduced.
4. **Audit writes are synchronous** on the request path (fast today; see §8 if audit
   volume grows).
5. **No brotli** (only gzip) — the stock `nginx:alpine` lacks the brotli module.
   gzip captures the large majority of the benefit; brotli is a future nicety.

---

## 8. Recommendations for future scaling

**Serving / infra**
- Deploy the production images to a **Linux host** (removes the Windows proxy tax
  entirely) behind TLS. Consider `pm.max_children`/`pm.max_requests` tuning in
  `www.conf` for the target box, and OPcache **preloading** (a Laravel preload script)
  for a further boot reduction.
- Add **brotli** (an nginx image with the module, or precompressed `.br`/`.gz` assets
  via a Vite compression plugin) alongside gzip.
- Put a CDN in front of `/assets/*` (already `immutable`), and add security response
  headers (CSP, HSTS, X-Content-Type-Options) at the edge.

**Backend as data grows**
- Move audit/notification writes to the (already-present) **queue** if audit volume
  becomes significant.
- Add the **trigram/GIN** index for directory search and an index on
  `users.last_login_at` when row counts climb.
- Add DB connection pooling (PgBouncer) and Redis persistence tuning at scale.

**Frontend**
- Keep `manualChunks` narrow; when Phase 2.4+ adds heavy libs (charts, editors),
  lazy-load them per route and give them their own chunks.
- Add a bundle-size check to CI to prevent regressions.

**Process**
- Enable `config:cache`/`route:cache` checks in CI (they catch new route closures,
  which silently break `route:cache`).

---

## How to run the production stack (isolated from dev)

> ⚠️ **Always use the project flag `-p sccit_prod`** — the repo's root `.env` sets
> `COMPOSE_PROJECT_NAME=sccit`, which would otherwise place the prod stack in the dev
> project and disrupt dev containers. The `make prod-*` targets bake this in.

```bash
make prod-build     # docker compose -p sccit_prod -f compose.prod.yaml build
make prod-up        #   … up -d      (serves http://localhost:8081)
make prod-setup     #   … migrate --force && db:seed --force
make prod-down      #   … down       (keeps data volumes; dev untouched)
```

Dev and prod can run simultaneously (:8080 and :8081), though on a memory-constrained
machine prefer not to run heavy image builds while both full stacks are up.

---

## Verification (no regressions)

- **Frontend unit tests:** 30/30 passing after the Vite change.
- **Backend Pest suite:** **109 passed (349 assertions), 0 failures** (parallel, against the dedicated test DB).
- **Functional parity on prod:** login → `/api/user` → `/api/admin/users` →
  `/api/admin/users/dashboard` → `/api/admin/roles` all return 200 with identical
  response shapes; `/api/health` returns byte-identical JSON to dev.
- **Static assets:** confirmed on the wire — `Content-Encoding: gzip` +
  `Cache-Control: public, max-age=31536000, immutable` on `/assets/*`, `no-cache` on
  `index.html`.
- **Dev isolation:** verified that `prod up` and `prod down` leave every dev
  container untouched (identical container IDs) and dev `/api/health` healthy.

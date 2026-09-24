# Testing & Quality Gates

What must pass before a change is done, and how to run it. The browser and
accessibility harnesses were introduced by **WP-2.7d**; the Pest and Vitest
suites predate it.

Everything runs **inside the containers**. The host has neither the PHP
extensions nor the Linux-native `node_modules`, and a gate that only works on
one machine is not a gate.

---

## 1. The gate

```sh
sh scripts/gates.sh                     # everything
sh scripts/gates.sh --only backend
sh scripts/gates.sh --only frontend --skip-build
sh scripts/gates.sh --with-e2e --with-a11y
```

| # | Gate | Command it runs |
|---|---|---|
| 1 | Pint (format) | `./vendor/bin/pint --test` |
| 2 | PHPStan (Larastan, level 6) | `./vendor/bin/phpstan analyse` |
| 3 | Pest | `./vendor/bin/pest` |
| 4 | Migration status | `php artisan migrate:status`, asserting no `Pending` |
| 5 | TypeScript | `npx tsc -b` |
| 6 | ESLint | `npm run lint` |
| 7 | Prettier | `npm run format:check` |
| 8 | Vitest | `npm run test` |
| 9 | Production build | `npm run build` |
| 10 | Browser E2E *(opt-in)* | `scripts/e2e.sh --project e2e` |
| 11 | Accessibility *(opt-in)* | `scripts/e2e.sh --project a11y` |

Cheapest and most localising first: a Pint failure is a diff you fix in a
second, and type-checking before the suites means a broken signature is reported
as a type error rather than as forty unrelated test failures.

**Every gate is always attempted** — an early failure does not abort the run, so
one invocation tells you everything that is broken. Exit status is the number of
failed gates.

Gate 4 exists because `migrate:status` exits `0` even with migrations pending.
A deployment whose schema is behind its code is invisible to every other gate.

### Pest is never parallelised

The suites share **one** test database. Two concurrent Pest runs fabricate
failures that look exactly like real regressions and cost an afternoon to
disbelieve. `gates.sh` runs strictly one gate at a time for that reason — do not
"speed it up".

### Known pre-existing flakes

Two Phase 2.4/2.5 tests fail intermittently on random-factory collisions, both
recorded before WP-2.7d and **not** caused by it:

- `Tests\Feature\Locations\LocationArchiveTest` — `FloorFactory` draws a random
  `floor_number` that collides on the `(building_id, floor_number)` unique index.
- an asset-directory search test — `toHaveCount(1)` against random factory data.

Re-run the file before investigating. Fixing them is not in WP-2.7d's scope.

---

## 2. Browser suite (Playwright)

```sh
sh scripts/e2e.sh                          # --project e2e (default)
sh scripts/e2e.sh --project a11y
sh scripts/e2e.sh --project smoke          # against production :8081
sh scripts/e2e.sh --project e2e --grep "QR"
```

`scripts/e2e.sh` seeds the deterministic fixtures first, then invokes Playwright
in the `node` container. Running `npx playwright test` by hand fails with an
explanatory error rather than a mystery — the suite reads its accounts from the
manifest the script writes.

| Project | Target | Covers |
|---|---|---|
| `e2e` | dev `:8080` | three-role authentication, authorization boundaries, QR workflow |
| `a11y` | dev `:8080` | axe-core regression against the committed baseline |
| `smoke` | prod `:8081` | does the **deployed bundle** boot in a browser |

### Deterministic fixtures

```sh
docker compose exec -T app php artisan sccit:e2e-fixtures
docker compose exec -T app php artisan sccit:e2e-fixtures --json
```

`DemoSeeder` builds its dataset from factories, so every email and QR code
differs between runs — a browser test cannot sign in as "whoever the factory
invented this time". `sccit:e2e-fixtures` creates a small **named** set instead
and prints exactly what it created:

| | |
|---|---|
| Administrator | `e2e.admin@sccit.test` |
| Technician | `e2e.tech@sccit.test` |
| Teacher | `e2e.teacher@sccit.test` |
| Password | `E2ePassw0rd!23` (override with `E2E_PASSWORD`) |
| QR label | `PC-E2EFIXTURE` on PC unit `E2E-PC-001`, with an in-progress maintenance record assigned to the fixture technician |

It is idempotent (matched on natural keys) and **refuses to run under
`APP_ENV=production`** — these accounts share one documented password, so on a
production target they would be a back door, not a fixture. That guard has its
own test: `backend/tests/Feature/Verification/SeedE2eFixturesTest.php`.

### Two mechanics worth knowing before you edit a spec

**The browser must believe it is on `localhost`.** Sanctum scopes its cookies to
the origins in `SANCTUM_STATEFUL_DOMAINS`. If the browser simply visited
`http://nginx/`, every cookie would be third-party and every authenticated test
would fail for a reason unrelated to the application. Chromium's
`--host-resolver-rules` keeps the origin as `localhost:<port>` while sending the
packets to the container that can answer — `nginx:80` for dev, and
`host.docker.internal:8081` for production, whose network the dev containers
deliberately cannot route to.

**API assertions run inside the page, not via `page.request`.** Playwright's
request context runs in Node, *outside* the browser, so the host-resolver
mapping does not apply to it — it fails with `ECONNREFUSED` before reaching the
application. `apiFetch()` in `e2e/fixtures/app.ts` runs `fetch` in the document,
carrying the session cookie and XSRF header exactly as the app's axios client
does.

Two smaller ones: `settle()` waits for the lazy-route spinner
(`svg.animate-spin`) to detach, because a navigation resolves long before the
route exists; and labels are matched with anchored regexes (`/^Password/`)
because `Field` renders a required marker inside the `<label>`, making its text
`"Password *"`.

### One worker, no retries

Both stacks share a single database, so parallel workers would mutate each
other's fixtures. Retries are off because a suite that passes on the second
attempt is telling you something. Set `E2E_RETRIES` when triaging.

### `test.fail()` marks a known product defect

One QR test is annotated `test.fail()`: signing in from a scanned label
navigates to the panel and is then bounced to the dashboard by `GuestRoute`,
breaking FR-QR-011's resume (and the ordinary return-to-intended-page
behaviour). The fix is outside WP-2.7d's scope, so the harness **records** it
instead of hiding it. The suite turns red the moment somebody fixes it, at which
point the annotation should be deleted.

---

## 3. Accessibility regression (axe-core)

```sh
sh scripts/e2e.sh --project a11y
```

Audits public, administrator, technician and teacher surfaces against **WCAG 2.0
/ 2.1 levels A and AA**, as the role that actually owns each screen — auditing
an administrator page as a teacher would only ever measure the 403 page.

This is a **regression** gate, not a conformance audit: the point is that these
screens can only get better from here. The full WCAG AA audit is SPMP WP-2.11.

- `frontend/e2e/a11y-baseline.json` records the violations that already existed
  when the harness landed, keyed per route (and per role for `/app`, which
  renders a different dashboard for each).
- A run fails when a route produces a **serious or critical** violation not in
  its baseline.
- Fixing a baselined issue never fails the build — the run prints a note asking
  for the stale entry to be removed.
- `minor` and `moderate` findings do not gate. They are dominated by advisory
  rules where gating trains people to add exceptions.

The baseline is **debt**. Entries should be deleted, never added, outside a
deliberate and recorded decision.

---

## 4. Authorization tests are not optional

From `CLAUDE.md` §6, and the reason the browser suite asserts every boundary
twice — once at the API through the page's own session, once at the surface the
user sees:

- Every module gets a dedicated authorization test file asserting the **negative**
  cases (403/404) role by role, not just the happy path.
- For row-scoped modules, a record absent from a user's list must be **equally
  unreachable by direct UUID**. A list filter without the matching single-record
  check is an IDOR.
- Redacted projections are asserted against the **encoded payload**, so a field
  cannot leak back in through a later resource change.

A UI that hides a link while the endpoint stays open is the vulnerability; a UI
that shows a link to an endpoint that refuses it is a bug. Only checking both
tells them apart.

---

## 5. Reporting results

Paste real counts and real failures. Never describe a gate as passing that you
did not run. Playwright writes a machine-readable summary to
`frontend/e2e/.artifacts/results.json`; traces and screenshots for failures land
in `frontend/test-results/` (all gitignored).

Related: [DEVELOPMENT.md](DEVELOPMENT.md) · [OPERATIONS.md](OPERATIONS.md) · [DOCKER.md](DOCKER.md)

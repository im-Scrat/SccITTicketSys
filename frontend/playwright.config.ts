import { defineConfig, devices } from '@playwright/test'

/**
 * Browser verification harness — WP-2.7d.
 *
 * ── Where this runs ───────────────────────────────────────────────────────
 * Inside the `node` container, never on the host. The host has no Linux
 * Chromium and no Linux-native node_modules, and a harness that only works on
 * one machine is the problem this work package exists to remove. Drive it with
 * `sh scripts/e2e.sh`, which seeds fixtures first and then invokes this config.
 *
 * ── The host-resolver trick ───────────────────────────────────────────────
 * Sanctum authenticates the SPA with first-party cookies scoped to the origin
 * in SANCTUM_STATEFUL_DOMAINS (localhost:8080 / localhost:8081). If the browser
 * simply visited http://nginx/ the origin would be `nginx`, every cookie would
 * be third-party, and every authenticated test would fail for a reason that has
 * nothing to do with the application.
 *
 * So the browser keeps believing it is on `localhost:<port>` while Chromium's
 * own resolver sends the packets to the container that can answer:
 *
 *   dev  (:8080)  ->  the `nginx` service, over the shared sccit_network
 *   prod (:8081)  ->  host.docker.internal, because the production stack sits
 *                     on an isolated network the dev containers cannot route to
 *                     — the published host port is the only path in, and that
 *                     isolation is deliberate (see compose.prod.yaml).
 *
 * ── Why one worker and no retries ─────────────────────────────────────────
 * Both stacks share a single database per stack, so parallel workers would
 * mutate each other's fixtures and invent failures. Retries are off by default
 * for the same reason a flaky gate is worse than no gate: a suite that passes
 * on the second attempt is telling you something, and hiding it converts a real
 * defect into background noise. Set E2E_RETRIES to override when triaging.
 */

const DEV_PORT = process.env.E2E_DEV_PORT ?? '8080'
const PROD_PORT = process.env.E2E_PROD_PORT ?? '8081'

/** Where the browser should actually send traffic for a given published port. */
const resolverRule = (port: string, target: string) =>
  `--host-resolver-rules=MAP localhost:${port} ${target}`

const chromium = {
  ...devices['Desktop Chrome'],
  // Alpine's system Chromium — Playwright's own download is a glibc build and
  // cannot run in this musl image. Baked by docker/node/Dockerfile.
  launchOptions: {
    executablePath: process.env.CHROMIUM_PATH ?? '/usr/bin/chromium',
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  },
}

export default defineConfig({
  testDir: './e2e',
  outputDir: './test-results',

  // The dev stack compiles each lazy route chunk on first visit, and re-optimises
  // the whole dependency graph whenever package.json changes — so a cold first
  // navigation is genuinely slow, and the first few after a dependency change
  // are slower still. These ceilings are generous on purpose: a timeout here
  // should mean "broken", not "cold".
  timeout: 150_000,
  expect: { timeout: 20_000 },

  fullyParallel: false,
  workers: 1,
  retries: Number(process.env.E2E_RETRIES ?? 0),
  forbidOnly: true,

  reporter: [['list'], ['json', { outputFile: './e2e/.artifacts/results.json' }]],

  use: {
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'off',
    actionTimeout: 20_000,
    navigationTimeout: 45_000,
  },

  projects: [
    {
      // Functional regression: three-role authentication, the authorization
      // boundaries between them, and the QR-verified technician workflow.
      name: 'e2e',
      testMatch:
        /specs\/(auth|qr-workflow|notifications|announcements|floor-plan|predictions)\.spec\.ts/,
      use: {
        ...chromium,
        baseURL: `http://localhost:${DEV_PORT}`,
        launchOptions: {
          ...chromium.launchOptions,
          args: [...chromium.launchOptions.args, resolverRule(DEV_PORT, 'nginx:80')],
        },
      },
    },
    {
      // Accessibility regression (axe-core) across the same surfaces.
      name: 'a11y',
      testMatch: /specs\/a11y\.spec\.ts/,
      use: {
        ...chromium,
        baseURL: `http://localhost:${DEV_PORT}`,
        launchOptions: {
          ...chromium.launchOptions,
          args: [...chromium.launchOptions.args, resolverRule(DEV_PORT, 'nginx:80')],
        },
      },
    },
    {
      // Production-target smoke: data-light by design. It must pass against a
      // stack that has no fixtures and no demo data, because that is what a
      // production target is.
      name: 'smoke',
      testMatch: /specs\/smoke\.spec\.ts/,
      use: {
        ...chromium,
        baseURL: `http://localhost:${PROD_PORT}`,
        launchOptions: {
          ...chromium.launchOptions,
          args: [
            ...chromium.launchOptions.args,
            resolverRule(PROD_PORT, `host.docker.internal:${PROD_PORT}`),
          ],
        },
      },
    },
  ],
})

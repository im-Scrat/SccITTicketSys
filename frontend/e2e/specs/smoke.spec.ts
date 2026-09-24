import { expect, test, type Page } from '@playwright/test'
import { apiFetch, EMAIL_LABEL, PASSWORD_LABEL, settle } from '../fixtures/app'

/**
 * Production-target browser smoke — WP-2.7d.
 *
 * ── Deliberately data-light ───────────────────────────────────────────────
 * This runs against the production stack, which has no fixtures, no demo data
 * and no shared-password accounts — `sccit:e2e-fixtures` refuses to run there
 * by design. So nothing here signs in. What it verifies is the half that
 * `scripts/smoke.sh` cannot reach from curl: that the built bundle actually
 * BOOTS in a browser.
 *
 * A production deployment can serve a 200 for every URL, pass every container
 * healthcheck, and still render a blank page — a bundle that throws on load, a
 * CSP that blocks its own scripts, a font or asset the policy forbids. Each of
 * those is invisible to an HTTP check and obvious to a browser.
 *
 * ── The theme script is the CSP canary ────────────────────────────────────
 * index.html carries an inline anti-FOUC script allowed by a single sha256 hash
 * in the production CSP. If the script changes and the hash does not, the
 * browser silently blocks it and the app flashes the wrong theme on every load.
 * `document.documentElement[data-theme]` is present only if that script ran, so
 * one attribute check catches the whole class of drift on the deployed artifact
 * rather than in a config file.
 */

type PageProblems = {
  consoleErrors: string[]
  cspViolations: string[]
  failedRequests: string[]
  unexpectedResponses: string[]
}

/**
 * Every request the anonymous landing page is *supposed* to make that does not
 * return 2xx.
 *
 * There is exactly one. The SPA probes the session on boot, and for a visitor
 * who is not signed in that probe correctly answers 401 — which is the app
 * working, not failing. Chromium still writes "Failed to load resource: the
 * server responded with a status of 401" to the console for it.
 *
 * So the console cannot be asserted empty; it would fail on correct behaviour,
 * and the obvious fix — ignoring every console error — would blind the check
 * to the bundle errors it exists to catch. Instead the expected response is
 * named here, generic resource-load lines are separated from real script
 * errors, and anything else that fails is reported with its URL and status.
 */
const EXPECTED_ANONYMOUS_RESPONSES: { pathname: string; status: number }[] = [
  { pathname: '/api/user', status: 401 },
]

/** Chromium's generic line for any non-2xx subresource; carries no URL. */
const RESOURCE_LOAD_LINE = /^Failed to load resource:/

/**
 * Collects everything the browser complains about. Installed before the first
 * navigation, because the interesting failures happen during initial load.
 */
function watch(page: Page): PageProblems {
  const problems: PageProblems = {
    consoleErrors: [],
    cspViolations: [],
    failedRequests: [],
    unexpectedResponses: [],
  }

  page.on('console', (message) => {
    if (message.type() !== 'error') return
    const text = message.text().slice(0, 300)
    // Attributed by URL through the response handler below instead — this line
    // on its own cannot tell an expected 401 from a missing chunk.
    if (RESOURCE_LOAD_LINE.test(text)) return
    problems.consoleErrors.push(text)
  })

  // An uncaught exception is never expected: it means the deployed bundle threw.
  page.on('pageerror', (error) => problems.consoleErrors.push(`uncaught: ${String(error)}`))

  page.on('response', (response) => {
    if (response.status() < 400) return
    const { pathname } = new URL(response.url())
    const expected = EXPECTED_ANONYMOUS_RESPONSES.some(
      (candidate) => candidate.pathname === pathname && candidate.status === response.status(),
    )
    if (expected) return
    problems.unexpectedResponses.push(`${response.status()} ${response.url().slice(0, 160)}`)
  })

  page.on('requestfailed', (request) => {
    const failure = request.failure()?.errorText ?? 'unknown'
    // A cancelled navigation preflight is noise, not a deployment fault.
    if (failure.includes('ERR_ABORTED')) return
    problems.failedRequests.push(`${request.method()} ${request.url().slice(0, 160)} — ${failure}`)
  })

  return problems
}

async function collectCspViolations(page: Page): Promise<void> {
  await page.addInitScript(() => {
    ;(window as unknown as { __csp: string[] }).__csp = []
    document.addEventListener('securitypolicyviolation', (event) => {
      ;(window as unknown as { __csp: string[] }).__csp.push(
        `${event.effectiveDirective || event.violatedDirective} blocked ${event.blockedURI}`,
      )
    })
  })
}

async function drainCsp(page: Page): Promise<string[]> {
  return page.evaluate(() => {
    const w = window as unknown as { __csp?: string[] }
    const out = w.__csp ?? []
    w.__csp = []

    return out
  })
}

test.describe('production-target smoke', () => {
  test('the landing page boots with no console errors or CSP violations', async ({ page }) => {
    const problems = watch(page)
    await collectCspViolations(page)

    await page.goto('/', { waitUntil: 'domcontentloaded' })
    await settle(page)

    // The SPA mounted and rendered something — not just an empty #root.
    const rendered = await page.locator('#root').innerText()
    expect(rendered.trim().length, 'the SPA rendered no content into #root').toBeGreaterThan(0)

    problems.cspViolations.push(...(await drainCsp(page)))

    expect(problems.cspViolations, 'CSP blocked resources the app needs').toEqual([])
    expect(problems.consoleErrors, 'the deployed bundle logged errors on load').toEqual([])
    expect(problems.failedRequests, 'the deployed bundle requested resources that failed').toEqual(
      [],
    )
  })

  test('the inline theme script survived the production CSP', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' })
    await settle(page, 0)

    const theme = await page.evaluate(() => document.documentElement.getAttribute('data-theme'))

    expect(
      theme,
      'data-theme is unset, so the CSP-hashed inline theme script did not run — ' +
        'the hash in docker/nginx/security-headers.prod.conf no longer matches index.html',
    ).not.toBeNull()
  })

  test('a client-side route loads directly through the SPA fallback', async ({ page }) => {
    const problems = watch(page)

    await page.goto('/sign-in', { waitUntil: 'domcontentloaded' })
    await settle(page)

    await expect(page.getByLabel(EMAIL_LABEL)).toBeVisible()
    await expect(page.getByLabel(PASSWORD_LABEL)).toBeVisible()
    await expect(page.getByRole('button', { name: /sign in/i })).toBeVisible()

    expect(problems.consoleErrors).toEqual([])
  })

  test('the deployed API answers its health check', async ({ page }) => {
    // Loaded first because apiFetch runs inside the document, which is the only
    // context the browser's host-resolver mapping applies to.
    await page.goto('/', { waitUntil: 'domcontentloaded' })

    const { status, body } = await apiFetch(page, '/api/health')
    expect(status).toBe(200)

    const health = JSON.parse(body) as { status: string; checks: Record<string, string> }
    expect(health.status).toBe('healthy')
    expect(health.checks.database).toBe('ok')
    expect(health.checks.redis).toBe('ok')
  })

  test('an anonymous request for a protected resource is refused', async ({ page }) => {
    await page.goto('/', { waitUntil: 'domcontentloaded' })

    expect(await apiFetch(page, '/api/user').then((r) => r.status)).toBe(401)
  })
})

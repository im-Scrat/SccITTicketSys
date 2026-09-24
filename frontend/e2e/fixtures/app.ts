import { expect, type Page } from '@playwright/test'
import { account, type Role } from './manifest'

/**
 * Shared browser helpers for the WP-2.7d suites.
 *
 * Every route in this SPA is lazy-loaded, so a navigation resolves long before
 * the page exists: React Router renders `<PageLoader>` — an `svg.animate-spin`
 * — while the chunk downloads, and on the dev stack Vite compiles that chunk on
 * first request. Asserting immediately after `goto` therefore tests the spinner.
 * `settle()` waits for the spinner to detach, which is the app's own signal
 * that a route has finished mounting.
 */
export async function settle(page: Page, quiet = 400): Promise<void> {
  await page
    .waitForSelector('svg.animate-spin', { state: 'detached', timeout: 45_000 })
    .catch(() => {
      // A route that never showed a spinner (cached chunk, instant render)
      // resolves here immediately — not an error.
    })
  await page.waitForLoadState('networkidle').catch(() => {})
  if (quiet > 0) await page.waitForTimeout(quiet)
}

/**
 * `Field` renders a required marker inside the `<label>`, so the label's text is
 * "Email *", not "Email". An exact match therefore finds nothing, and a loose
 * substring match on "Password" also matches the visibility toggle's
 * "Show password" aria-label — two elements, and a strict-mode failure.
 *
 * An anchored regex is the locator that means what it says: this label, and not
 * the button beside it.
 */
export const EMAIL_LABEL = /^Email/
export const PASSWORD_LABEL = /^Password/

export async function goto(page: Page, path: string): Promise<void> {
  await page.goto(path, { waitUntil: 'domcontentloaded' })
  await settle(page)
}

/**
 * Sign in through the real form — not by injecting a cookie.
 *
 * Sanctum's SPA flow is a sequence (CSRF cookie, credentialed POST, session
 * cookie) and its most likely failure mode is a misconfigured origin, which a
 * fabricated session would hide completely. Driving the form is the only way
 * the test exercises what a user exercises.
 */
export async function signIn(page: Page, role: Role): Promise<void> {
  const { email, password } = account(role)

  if (!page.url().includes('/sign-in')) {
    await goto(page, '/sign-in')
  }

  await page.getByLabel(EMAIL_LABEL).fill(email)
  await page.getByLabel(PASSWORD_LABEL).fill(password)
  await page.getByRole('button', { name: /sign in/i }).click()

  /*
   * Wait for the workspace SHELL, not for the URL to change.
   *
   * Two reasons, and the second one bit this suite before it was written down.
   * First, the shell is the real proof of an established session: a URL change
   * alone also matches a redirect back to an error page. Second, on a cold dev
   * stack Vite compiles the destination's chunk on first visit, so the URL can
   * change tens of seconds before anything renders — waiting on the URL turns
   * an ordinary cold start into a timeout that looks like a broken login.
   *
   * The ceiling is deliberately well past a warm navigation. A failure here
   * should mean "sign-in is broken", never "the bundler was busy".
   */
  await expect(page.getByRole('navigation', { name: 'Primary' })).toBeVisible({ timeout: 75_000 })
  await settle(page)
}

/**
 * Call the API the way the SPA does: from inside the page.
 *
 * NOT via Playwright's `page.request`. That context runs in Node, outside the
 * browser, so Chromium's `--host-resolver-rules` mapping does not apply to it —
 * it resolves `localhost` inside the container, finds nothing listening, and
 * fails with ECONNREFUSED before the application is ever reached.
 *
 * Running the request in the page is also the more honest test: it carries the
 * session cookie and the XSRF header exactly as the application's own axios
 * client does, so what is asserted is the path a user's browser actually takes.
 */
export async function apiFetch(
  page: Page,
  path: string,
  method = 'GET',
): Promise<{ status: number; body: string }> {
  return page.evaluate(
    async ({ path, method }) => {
      const xsrf = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.split('=')[1]

      const response = await fetch(path, {
        method,
        credentials: 'include',
        headers: {
          Accept: 'application/json',
          ...(xsrf === undefined ? {} : { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }),
        },
      })

      return { status: response.status, body: (await response.text()).slice(0, 2000) }
    },
    { path, method },
  )
}

/**
 * The status code the API returns for a path, using the page's own session.
 * Used to assert authorization boundaries at the source rather than inferring
 * them from whatever the UI happens to render for a refused request.
 */
export async function apiStatus(page: Page, path: string): Promise<number> {
  return (await apiFetch(page, path)).status
}

export async function signOut(page: Page): Promise<void> {
  await apiFetch(page, '/api/logout', 'POST').catch(() => {})
  await page.context().clearCookies()
}

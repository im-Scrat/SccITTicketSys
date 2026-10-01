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
  /** JSON body for a write. Omitted entirely for reads, which must send none. */
  body?: unknown,
): Promise<{ status: number; body: string }> {
  return page.evaluate(
    async ({ path, method, body }) => {
      const xsrf = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.split('=')[1]

      const response = await fetch(path, {
        method,
        credentials: 'include',
        headers: {
          Accept: 'application/json',
          ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
          ...(xsrf === undefined ? {} : { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }),
        },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
      })

      return { status: response.status, body: (await response.text()).slice(0, 2000) }
    },
    { path, method, body },
  )
}

/**
 * Establish a session for `role` **without driving the sign-in form**.
 *
 * Used only to *arrange* state — WP-2.7b's specs need a ticket assigned, a
 * comment posted and an internal note written by three different people before
 * the surface under test has anything on it, and driving six form sign-ins to
 * set that up would spend six minutes proving something `auth.spec.ts` already
 * proves once.
 *
 * The act and the assertion still go through the browser: every notification
 * test below signs in with {@link signIn} and reads the real rendered surface.
 * This helper is for the fixture, never for the thing being verified.
 */
export async function apiLogin(page: Page, role: Role): Promise<void> {
  const { email, password } = account(role)

  /*
   * End the previous session first, and do not skip this.
   *
   * `AuthenticateSession` stores the signed-in user's password hash in the
   * session and logs out any request whose stored hash no longer matches the
   * authenticated user. `session()->regenerate()` on login changes the session
   * *id* but keeps its *data*, so signing a second person in on top of a live
   * session leaves the first person's hash behind — and the very next guarded
   * request is thrown out with a 401 that looks nothing like its cause.
   *
   * Logging out invalidates the session outright, which is also what actually
   * happens when a person switches accounts.
   */
  await apiFetch(page, '/api/logout', 'POST').catch(() => undefined)

  await page.evaluate(async () => {
    await fetch('/sanctum/csrf-cookie', { credentials: 'include' })
  })

  const response = await apiFetch(page, '/api/login', 'POST', { email, password })

  if (response.status !== 200) {
    throw new Error(`apiLogin(${role}) failed with ${response.status}: ${response.body}`)
  }
}

/**
 * The same call as {@link apiFetch}, but parsed **inside the page**.
 *
 * `apiFetch` truncates the body to 2000 characters on purpose — it exists to
 * assert status codes, and a full response in every trace would make failures
 * harder to read, not easier. That truncation is fatal to a parser: a page of
 * twenty notifications is comfortably past the limit, so `JSON.parse` on the
 * returned string fails on a response that was perfectly valid.
 *
 * Parsing in the browser avoids the problem rather than raising the limit,
 * which would only move it.
 */
export async function apiJson<T>(
  page: Page,
  path: string,
  method = 'GET',
  body?: unknown,
): Promise<{ status: number; data: T }> {
  const result = await page.evaluate(
    async ({ path, method, body }) => {
      const xsrf = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.split('=')[1]

      const response = await fetch(path, {
        method,
        credentials: 'include',
        headers: {
          Accept: 'application/json',
          ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
          ...(xsrf === undefined ? {} : { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }),
        },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
      })

      const text = await response.text()

      try {
        return { status: response.status, data: JSON.parse(text) as unknown, raw: null }
      } catch {
        return { status: response.status, data: null, raw: text.slice(0, 300) }
      }
    },
    { path, method, body },
  )

  if (result.raw !== null) {
    throw new Error(`Expected JSON from ${path}, got ${result.status}: ${result.raw}`)
  }

  return { status: result.status, data: result.data as T }
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

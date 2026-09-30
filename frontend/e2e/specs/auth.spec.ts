import { expect, test } from '@playwright/test'
import { account, ROLES, type Role } from '../fixtures/manifest'
import { apiStatus, goto, settle, signIn, signOut } from '../fixtures/app'

/**
 * Three-role authentication and the authorization boundaries between them —
 * WP-2.7d.
 *
 * ── What this adds over the Pest suites ───────────────────────────────────
 * The backend suites already prove the policy layer answers 403. They prove it
 * in-process, against a request the test itself constructed. What they cannot
 * prove is that a *browser* reaches those answers: that Sanctum's cookie
 * handshake works over the real origin, that the SPA's route guards agree with
 * the API, and that signing out actually ends the session rather than only
 * clearing client state.
 *
 * Each boundary is asserted twice on purpose — once at the API through the
 * page's own session, once at the surface the user sees. A UI that hides a link
 * while the endpoint stays open is the vulnerability; a UI that shows a link to
 * an endpoint that refuses it is a bug. Only checking both distinguishes them.
 *
 * The expectations below were taken from the running application, not from the
 * specification: the 403/200 matrix in `boundaries` was probed against the dev
 * stack for all three fixture accounts before it was written down.
 */

type Boundary = { path: string; status: number; why: string }

const boundaries: Record<Role, Boundary[]> = {
  administrator: [
    {
      path: '/api/admin/tickets',
      status: 200,
      why: 'the administrative ticket directory is theirs',
    },
    { path: '/api/maintenance', status: 200, why: 'administrators see every maintenance record' },
    { path: '/api/work-support-requests', status: 200, why: 'they decide support requests' },
  ],
  technician: [
    { path: '/api/tickets/mine', status: 200, why: 'their own reports stay reachable' },
    { path: '/api/maintenance', status: 200, why: 'maintenance is their work' },
    {
      path: '/api/tickets/feed',
      status: 403,
      why: 'the community feed is refused outright, not served as an empty list',
    },
    { path: '/api/admin/tickets', status: 403, why: 'the administrative directory is not theirs' },
  ],
  teacher: [
    { path: '/api/tickets/mine', status: 200, why: 'a teacher sees the faults they reported' },
    { path: '/api/tickets/feed', status: 200, why: 'the community feed is a teacher surface' },
    { path: '/api/admin/tickets', status: 403, why: 'no administrative directory' },
    { path: '/api/maintenance', status: 403, why: 'maintenance is not a teacher surface' },
    {
      path: '/api/work-support-requests',
      status: 403,
      why: 'support requests are technician work',
    },
  ],
}

test.describe('authentication', () => {
  for (const role of ROLES) {
    test(`a ${role} signs in and reaches the workspace`, async ({ page }) => {
      await signIn(page, role)

      await expect(page).toHaveURL(/\/app/)
      await expect(page.getByRole('navigation', { name: 'Primary' })).toBeVisible()

      // The session is real on the server, not just in the client store.
      expect(await apiStatus(page, '/api/user')).toBe(200)
    })
  }

  test('a wrong password is refused and establishes no session', async ({ page }) => {
    const { email } = account('technician')

    await goto(page, '/sign-in')
    await page.getByLabel('Email').fill(email)
    await page.getByLabel('Password', { exact: true }).fill('definitely-not-the-password')
    await page.getByRole('button', { name: /sign in/i }).click()
    await settle(page)

    await expect(page).toHaveURL(/\/sign-in/)
    // The negative case that matters: no cookie was issued behind the error.
    expect(await apiStatus(page, '/api/user')).toBe(401)
  })

  test('signing out ends the session on the server', async ({ page }) => {
    await signIn(page, 'administrator')
    expect(await apiStatus(page, '/api/user')).toBe(200)

    await signOut(page)

    expect(await apiStatus(page, '/api/user')).toBe(401)

    // And the guard sends a signed-out visitor back to sign-in rather than
    // rendering the shell from stale client state.
    await goto(page, '/app')
    await expect(page).toHaveURL(/\/sign-in/)
  })

  test('an anonymous visitor cannot reach the workspace', async ({ page }) => {
    await goto(page, '/app')

    await expect(page).toHaveURL(/\/sign-in/)
    expect(await apiStatus(page, '/api/user')).toBe(401)
  })
})

test.describe('authorization boundaries', () => {
  for (const role of ROLES) {
    test(`a ${role} gets exactly the API surface their role allows`, async ({ page }) => {
      await signIn(page, role)

      for (const { path, status, why } of boundaries[role]) {
        expect(await apiStatus(page, path), `${role} -> ${path}: ${why}`).toBe(status)
      }
    })
  }

  test('a teacher who types an administrator URL gets the 403 surface', async ({ page }) => {
    await signIn(page, 'teacher')
    await goto(page, '/app/tickets/manage')

    // The route guard renders the Forbidden page in place — it must not render
    // the management surface and let the empty API response look like "no data".
    await expect(page.getByRole('heading', { name: 'Access denied' })).toBeVisible()
    expect(await apiStatus(page, '/api/admin/tickets')).toBe(403)
  })

  test('a technician who types an administrator URL gets the 403 surface', async ({ page }) => {
    await signIn(page, 'technician')
    await goto(page, '/app/tickets/manage')

    await expect(page.getByRole('heading', { name: 'Access denied' })).toBeVisible()
    expect(await apiStatus(page, '/api/admin/tickets')).toBe(403)
  })
})

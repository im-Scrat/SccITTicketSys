import { expect, test } from '@playwright/test'
import { manifest } from '../fixtures/manifest'
import {
  apiFetch,
  apiJson,
  apiLogin,
  apiStatus,
  goto,
  settle,
  signIn,
  signOut,
} from '../fixtures/app'

/**
 * QR-verified technician job workflow — browser regression for WP-2.6b.
 *
 * ── Why this needs a browser at all ───────────────────────────────────────
 * Two requirements in this flow are enforced *in the client* and cannot be
 * proved by any backend test:
 *
 *   FR-QR-010  an unauthenticated scan is recorded and answered while
 *              disclosing nothing about the machine. The API's part is tested
 *              in Pest; what is untested is whether the page that runs before
 *              sign-in leaks the equipment it just looked up.
 *
 *   FR-QR-011  the scanned destination survives sign-in, carried as a CODE and
 *              rebuilt from a fixed template — never as a caller-supplied URL.
 *              That is `sessionStorage` plus a redirect, which exists only in
 *              a browser.
 *
 * So these tests do the thing a technician does: open a sticker's URL on a
 * signed-out phone, sign in, and land on the right machine.
 *
 * The label is the deterministic fixture code from `sccit:e2e-fixtures`; the
 * PC unit behind it carries an in-progress maintenance record assigned to the
 * fixture technician, so the panel has real work to open.
 */

const fixtures = () => manifest()

test.describe('QR scan entry', () => {
  test('an unauthenticated scan routes to sign-in without disclosing the machine', async ({
    page,
  }) => {
    const { qr, pc_unit, room } = fixtures()

    await goto(page, qr.scan_path)

    await expect(page).toHaveURL(/\/sign-in/)

    // FR-QR-010: the scan is answered, but nothing about the target is on the
    // page. Asserted against the rendered document rather than the API payload,
    // because the leak this guards against would be a client-side one.
    const body = (await page.locator('body').innerText()).toLowerCase()
    for (const secret of [pc_unit.pc_name, pc_unit.unit_code, room.name]) {
      expect(body, `the pre-authentication page must not disclose "${secret}"`).not.toContain(
        secret.toLowerCase(),
      )
    }
  })

  /**
   * The FR-QR-011 resume, end to end — as amended on 2026-10-19: every sign-in
   * lands on the dashboard, and the dashboard offers the scanned PC.
   */
  test('the scanned destination survives sign-in and is offered on the dashboard', async ({
    page,
  }) => {
    const { qr } = fixtures()

    await goto(page, qr.scan_path)
    await expect(page).toHaveURL(/\/sign-in/)

    await signIn(page, 'technician')

    // Sign-in lands on the dashboard (owner decision 2026-10-19), which offers
    // the scanned PC; following the offer opens its panel.
    await expect(page).toHaveURL(/\/app$/)
    await page.getByRole('button', { name: 'Continue to the PC you scanned' }).click()

    await expect(page).toHaveURL(new RegExp(`/app/qr/${qr.code}$`))
    expect(await apiStatus(page, `/api/qr/${qr.code}/panel`)).toBe(200)
  })

  test('the stored scan is spent once followed and cannot be replayed', async ({ page }) => {
    const { qr } = fixtures()

    const stored = () => page.evaluate(() => sessionStorage.getItem('sccit.pendingScan'))

    await goto(page, qr.scan_path)
    await expect(page).toHaveURL(/\/sign-in/)

    // The scan stored a CODE, never a URL (FR-QR-011 / DD-48).
    expect(await stored()).toBe(qr.code)

    await signIn(page, 'technician')
    await page.getByRole('button', { name: 'Continue to the PC you scanned' }).click()
    // Let the panel finish loading: signing out under its in-flight requests
    // lets a late response re-issue the session cookie.
    await expect(page).toHaveURL(new RegExp(`/app/qr/${qr.code}$`))
    await settle(page)

    // Spent on use: the next sign-in in this tab offers nothing to replay.
    expect(await stored()).toBeNull()

    await signOut(page)
    await goto(page, '/sign-in')
    await signIn(page, 'technician')

    await expect(page).toHaveURL(/\/app$/)
    await expect(page.getByRole('button', { name: 'Continue to the PC you scanned' })).toHaveCount(
      0,
    )
  })

  test('a signed-in technician scanning a label goes straight to the panel', async ({ page }) => {
    const { qr } = fixtures()

    await signIn(page, 'technician')
    await goto(page, qr.scan_path)

    await expect(page).toHaveURL(new RegExp(`/app/qr/${qr.code}$`))
  })
})

test.describe('QR authorization boundary', () => {
  test('a teacher is refused the scanned panel and told so without disclosure', async ({
    page,
  }) => {
    const { qr, pc_unit } = fixtures()

    await signIn(page, 'teacher')
    await goto(page, qr.scan_path)
    await settle(page)

    // The API is the authority, and it refuses.
    expect(await apiStatus(page, `/api/qr/${qr.code}/panel`)).toBe(403)

    // And the refusal the teacher reads names no equipment.
    const body = (await page.locator('body').innerText()).toLowerCase()
    expect(body).not.toContain(pc_unit.pc_name.toLowerCase())
    expect(body).not.toContain(pc_unit.unit_code.toLowerCase())
  })

  test('an unknown label is refused without revealing whether it exists', async ({ page }) => {
    await signIn(page, 'technician')
    await goto(page, '/qr/PC-NOTAREALCODE')
    await settle(page)

    await expect(
      page.getByRole('heading', { name: /not recognised|not available to you/i }),
    ).toBeVisible()
  })

  test('an anonymous scan of an unknown label answers the same way as a real one', async ({
    page,
  }) => {
    const { qr } = fixtures()

    // A real label sends an anonymous visitor to sign-in. An invented one must
    // not answer differently, or the difference itself is the disclosure.
    await goto(page, '/qr/PC-NOTAREALCODE')
    const unknownUrl = new URL(page.url()).pathname

    await page.context().clearCookies()
    await goto(page, qr.scan_path)
    const knownUrl = new URL(page.url()).pathname

    expect(unknownUrl).toBe(knownUrl)
  })
})

/**
 * The resume, its failure cases, and its redirect safety (FR-QR-011, DD-48).
 *
 * Labels for these cases are issued through the administrator API for a PC of
 * their own (unique per run), so revoking or archiving them never touches the
 * fixture label the tests above depend on.
 */
test.describe('QR sign-in resume — outcomes and safety', () => {
  const stamp = () => Date.now().toString(36).toUpperCase()

  /** A fresh PC with an active label; returns its code, uuid and name. */
  async function issueLabel(page: import('@playwright/test').Page) {
    const id = stamp()
    await goto(page, '/sign-in')
    await apiLogin(page, 'administrator')
    const pc = await apiJson<{ data: { id: string } }>(page, '/api/admin/pc-units', 'POST', {
      unit_code: `E2E-QR-${id}`,
      pc_name: `E2E Label PC ${id}`,
      status: 'available',
    })
    expect(pc.status, 'creating the PC').toBe(201)
    const qr = await apiJson<{ data: { code: string } }>(
      page,
      `/api/admin/pc-units/${pc.data.data.id}/qr`,
      'POST',
    )
    expect(qr.status, 'issuing the label').toBe(201)
    return { code: qr.data.data.code, uuid: pc.data.data.id, name: `E2E Label PC ${id}` }
  }

  async function expectNoDisclosure(page: import('@playwright/test').Page, secrets: string[]) {
    const body = (await page.locator('body').innerText()).toLowerCase()
    for (const secret of secrets) expect(body).not.toContain(secret.toLowerCase())
  }

  test('an administrator signing in from a scan lands on that machine', async ({ page }) => {
    const { qr, pc_unit } = fixtures()

    await goto(page, qr.scan_path)
    await expect(page).toHaveURL(/\/sign-in/)
    await signIn(page, 'administrator')
    // Sign-in lands on the dashboard (owner decision 2026-10-19), which offers
    // the scanned PC; following the offer opens its panel.
    await expect(page).toHaveURL(/\/app$/)
    await page.getByRole('button', { name: 'Continue to the PC you scanned' }).click()

    await expect(page).toHaveURL(new RegExp(`/app/qr/${qr.code}$`))
    await expect(page.getByText(pc_unit.unit_code).first()).toBeVisible()
    // The shared status vocabulary (DD-100) — never a connectivity claim.
    await expect(page.getByText(/Available|Pending Repair|Needs Fix/).first()).toBeVisible()
    await expectNoDisclosure(page, ['online', 'offline'])
  })

  test('a technician with no work on the scanned machine is refused after sign-in, naming nothing', async ({
    page,
  }) => {
    const label = await issueLabel(page)
    await signOut(page)

    await goto(page, `/qr/${label.code}`)
    await expect(page).toHaveURL(/\/sign-in/)
    await signIn(page, 'technician')
    // Sign-in lands on the dashboard (owner decision 2026-10-19), which offers
    // the scanned PC; following the offer opens its panel.
    await expect(page).toHaveURL(/\/app$/)
    await page.getByRole('button', { name: 'Continue to the PC you scanned' }).click()

    await expect(page).toHaveURL(new RegExp(`/app/qr/${label.code}$`))
    await expect(
      page.getByRole('heading', { name: 'You have no assigned work on this machine' }),
    ).toBeVisible()
    await expectNoDisclosure(page, [label.name, `E2E-QR-`])
  })

  test('a revoked label resumes to an "out of date" refusal, naming nothing', async ({ page }) => {
    const label = await issueLabel(page)
    const revoked = await apiFetch(page, `/api/admin/pc-units/${label.uuid}/qr/revoke`, 'POST', {})
    expect(revoked.status, `revoking: ${revoked.body}`).toBe(200)
    await signOut(page)

    // Anonymous: the same answer as any label — sign in — so nothing is disclosed.
    await goto(page, `/qr/${label.code}`)
    await expect(page).toHaveURL(/\/sign-in/)
    await signIn(page, 'technician')
    // Sign-in lands on the dashboard (owner decision 2026-10-19), which offers
    // the scanned PC; following the offer opens its panel.
    await expect(page).toHaveURL(/\/app$/)
    await page.getByRole('button', { name: 'Continue to the PC you scanned' }).click()

    await expect(page).toHaveURL(new RegExp(`/app/qr/${label.code}$`))
    await expect(page.getByRole('heading', { name: 'This label is out of date' })).toBeVisible()
    await expectNoDisclosure(page, [label.name])
  })

  test('the label of an archived machine is refused as out of date', async ({ page }) => {
    const label = await issueLabel(page)
    const archived = await apiFetch(page, `/api/admin/pc-units/${label.uuid}`, 'DELETE')
    expect(archived.status, `archiving: ${archived.body}`).toBeLessThan(300)
    await signOut(page)

    await signIn(page, 'technician')
    await goto(page, `/qr/${label.code}`)
    await settle(page)

    await expect(page.getByRole('heading', { name: 'This label is out of date' })).toBeVisible()
    await expectNoDisclosure(page, [label.name])
  })

  for (const poison of [
    'https://evil.test/x',
    '//evil.test/x',
    '/\\evil.test',
    'javascript:alert(1)',
    '/app/qr/../../evil',
  ]) {
    test(`a tampered pending scan (${poison}) cannot redirect sign-in`, async ({ page }) => {
      await goto(page, '/sign-in')
      await page.evaluate((value) => sessionStorage.setItem('sccit.pendingScan', value), poison)

      await signIn(page, 'technician')

      // Sign-in lands on the dashboard regardless, and a malformed value is
      // not a code, so nothing is offered to follow — and nothing leaves this origin.
      const url = new URL(page.url())
      expect(url.hostname).toBe('localhost')
      expect(url.pathname).toBe('/app')
      await expect(
        page.getByRole('button', { name: 'Continue to the PC you scanned' }),
      ).toHaveCount(0)
    })
  }

  test('a destination in the sign-in URL is ignored — there is no redirect parameter', async ({
    page,
  }) => {
    await goto(
      page,
      '/sign-in?redirect=https://evil.test&next=//evil.test&returnTo=/app/qr/PC-E2EFIXTURE',
    )

    await signIn(page, 'technician')

    const url = new URL(page.url())
    expect(url.hostname).toBe('localhost')
    expect(url.pathname).toBe('/app')
  })

  test('a scan survives sign-in and a forced password change, and is offered', async ({ page }) => {
    const { qr } = fixtures()
    const id = stamp().toLowerCase()
    const email = `e2e.forced.${id}@sccit.test`
    const first = 'Forced-Passw0rd!1'

    // A technician an administrator has flagged for a password change.
    await goto(page, '/sign-in')
    await apiLogin(page, 'administrator')
    const created = await apiFetch(page, '/api/admin/users', 'POST', {
      role: 'technician',
      first_name: 'Forced',
      last_name: `Tech ${id}`,
      email,
      status: 'active',
      password: first,
      password_confirmation: first,
      force_password_reset: true,
    })
    expect(created.status, `creating the account: ${created.body}`).toBe(201)
    await signOut(page)

    // Scan signed out, then sign in through the form (not the helper: the
    // change screen deliberately has no workspace shell to wait for).
    await goto(page, qr.scan_path)
    await expect(page).toHaveURL(/\/sign-in/)
    await page.getByLabel(/^Email/).fill(email)
    await page.getByLabel(/^Password/).fill(first)
    await page.getByRole('button', { name: /sign in/i }).click()

    await expect(page).toHaveURL(/\/change-password$/)
    await page.getByLabel(/^current password/i).fill(first)
    await page.getByLabel(/^new password/i).fill('Forced-Passw0rd!2')
    await page.getByLabel(/^confirm new password/i).fill('Forced-Passw0rd!2')
    await page.getByRole('button', { name: /set new password and continue/i }).click()

    // The change lands on the dashboard too; the scan is still offered there.
    // Sign-in lands on the dashboard (owner decision 2026-10-19), which offers
    // the scanned PC; following the offer opens its panel.
    await expect(page).toHaveURL(/\/app$/)
    await page.getByRole('button', { name: 'Continue to the PC you scanned' }).click()

    // Refused, because this new technician has no work on it, which proves the
    // panel (not the dashboard) was reached.
    await expect(page).toHaveURL(new RegExp(`/app/qr/${qr.code}$`))
    await expect(
      page.getByRole('heading', { name: 'You have no assigned work on this machine' }),
    ).toBeVisible()
  })

  test('signing in lands on the dashboard, not the protected page that asked for it', async ({
    page,
  }) => {
    await goto(page, '/app/notifications?filter=unread')
    await expect(page).toHaveURL(/\/sign-in/)

    await signIn(page, 'technician')

    // Owner decision 2026-10-19: every successful sign-in goes to the dashboard.
    await expect(page).toHaveURL(/\/app$/)
  })
})

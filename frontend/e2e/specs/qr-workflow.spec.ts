import { expect, test } from '@playwright/test'
import { manifest } from '../fixtures/manifest'
import { apiStatus, goto, settle, signIn, signOut } from '../fixtures/app'

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
   * The FR-QR-011 resume, end to end.
   *
   * This test was a recorded `test.fail()` throughout WP-2.7d: the destination
   * was computed correctly and then overwritten, because `SignInPage` and
   * `GuestRoute` both navigated and the guard ran last —
   * `[login 200] -> /app/qr/… -> /app`. The guard now owns the decision alone
   * (F-1), so the annotation is gone and this asserts the real requirement.
   */
  test('the scanned destination survives sign-in and opens the panel', async ({ page }) => {
    const { qr } = fixtures()

    await goto(page, qr.scan_path)
    await expect(page).toHaveURL(/\/sign-in/)

    // Signing in from here must resume the scan, not land on the dashboard.
    await signIn(page, 'technician')

    await expect(page).toHaveURL(new RegExp(`/app/qr/${qr.code}$`))
    expect(await apiStatus(page, `/api/qr/${qr.code}/panel`)).toBe(200)
  })

  test('the stored scan is consumed by sign-in and cannot be replayed', async ({ page }) => {
    const { qr } = fixtures()

    const stored = () => page.evaluate(() => sessionStorage.getItem('sccit.pendingScan'))

    await goto(page, qr.scan_path)
    await expect(page).toHaveURL(/\/sign-in/)

    // The scan stored a CODE, never a URL (FR-QR-011 / DD-48).
    expect(await stored()).toBe(qr.code)

    await signIn(page, 'technician')

    // Consumed on use. Asserted on the stored value rather than on the landing
    // URL deliberately: the redirect itself is subject to the known defect
    // above, but this invariant — a scan is spent once — holds either way, and
    // will keep holding after that defect is fixed.
    expect(await stored()).toBeNull()

    await signOut(page)
    await goto(page, '/sign-in')
    await signIn(page, 'technician')

    await expect(page).toHaveURL(/\/app$/)
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

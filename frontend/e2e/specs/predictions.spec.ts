import { expect, test, type Page } from '@playwright/test'
import { apiFetch, goto, settle, signIn, signOut } from '../fixtures/app'
import { manifest } from '../fixtures/manifest'

/**
 * Predictive-maintenance findings, through a browser — WP-M.
 *
 * ── What is arranged ──────────────────────────────────────────────────────
 *
 * `sccit:e2e-fixtures` files two pending findings on the fixture PC, shaped the
 * way the pipeline shapes them (frozen evidence, a detected pattern, a time
 * window's basis) and backed by three real completed repairs. They differ on
 * purpose: the first states a time window, the second withholds one and says
 * why. The suite asserts both branches of that display rule.
 *
 * ── The assertions that matter most are the negative ones ─────────────────
 *
 *  1. **A technician and a teacher cannot reach any of it** — not the list, not
 *     a finding by its real uuid, not the decision endpoints — and are answered
 *     identically for a uuid that does not exist.
 *  2. **Nothing but the verdict happens.** Confirming records a decision; it
 *     opens no ticket and schedules no visit (AI is advisory, FR-AI-032).
 *  3. **A decided finding is final.**
 *
 * Tests that decide a finding come last in the administrator block, and each
 * takes a different fixture finding, so the run depends on nothing beyond "read
 * before write".
 */

const withWindow = () => manifest().predictions.with_window
const withoutWindow = () => manifest().predictions.without_window

const LIST = '/app/predictions'
const UNKNOWN_UUID = '11111111-2222-4333-8444-555555555555'

/**
 * A JSON request made from inside the page, so it carries the session and the
 * XSRF header exactly as the SPA's own client does. Local to this spec:
 * `apiFetch` truncates its body to 2000 characters, which is fine for a status
 * check and useless for reading a finding's state or counting rows.
 */
async function pageJson<T>(
  page: Page,
  path: string,
  method = 'GET',
): Promise<{ status: number; data: T }> {
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

      return { status: response.status, data: (await response.json().catch(() => null)) as never }
    },
    { path, method },
  )
}

/** A finding's state as the API reports it — the page's claim, checked at the source. */
async function stateOf(page: Page, uuid: string): Promise<string | undefined> {
  const { status, data } = await pageJson<{ data?: { status: { value: string } } }>(
    page,
    `/api/admin/predictions/${uuid}`,
  )

  return status === 200 ? data.data?.status.value : undefined
}

test.describe('predictive maintenance — administrator', () => {
  test('lists the pending findings with the machine and risk, in words', async ({ page }) => {
    await signIn(page, 'administrator')
    await goto(page, LIST)

    await expect(
      page.getByRole('heading', { level: 1, name: 'Predictive maintenance' }),
    ).toBeVisible()

    const row = page.getByRole('link', { name: new RegExp(withWindow().issue) })
    await expect(row).toBeVisible()
    await expect(row).toContainText('E2E Fixture PC')
    await expect(row).toContainText('E2E-PC-001')
    await expect(row).toContainText('High risk')
    await expect(row).toContainText('Pending')

    await expect(page.getByRole('link', { name: new RegExp(withoutWindow().issue) })).toContainText(
      'Medium risk',
    )
  })

  test('says that nothing is acted on automatically', async ({ page }) => {
    await signIn(page, 'administrator')
    await goto(page, LIST)

    await expect(page.getByText(/nothing here is acted on automatically/i)).toBeVisible()
  })

  test('opens a finding and shows the machine, asset, location and the evidence', async ({
    page,
  }) => {
    await signIn(page, 'administrator')
    await goto(page, LIST)

    await page.getByRole('link', { name: new RegExp(withWindow().issue) }).click()
    await settle(page)

    await expect(page).toHaveURL(new RegExp(`${LIST}/${withWindow().uuid}$`))
    await expect(page.getByRole('heading', { level: 1, name: withWindow().issue })).toBeVisible()

    // PC, asset tag and where it sits.
    await expect(page.getByText(/E2E Fixture PC/).first()).toBeVisible()
    await expect(page.getByText(/E2E-AT-001/)).toBeVisible()
    await expect(page.getByText(/E2E Verification Lab/).first()).toBeVisible()

    // Risk, confidence, the model's reading and the evidence it rests on.
    await expect(page.getByText('High').first()).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Evidence' })).toBeVisible()
    await expect(page.getByText('Recurring Power Supply replacement').first()).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Components replaced' })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Explanation' })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Recommendation' })).toBeVisible()
  })

  test('shows the machine’s repair history now, apart from the frozen evidence', async ({
    page,
  }) => {
    await signIn(page, 'administrator')
    await goto(page, `${LIST}/${withWindow().uuid}`)

    const history = page.getByRole('region', { name: 'Repair history now' })

    await expect(history).toBeVisible()
    await expect(history).toContainText('Completed repairs')
    await expect(history).toContainText('Most recent repair')
    await expect(history.getByRole('heading', { name: 'Previous problems' })).toBeVisible()
    // Two earlier corrective repairs sit behind the most recent one.
    await expect(history.getByRole('listitem')).toHaveCount(2)
    await expect(history.getByRole('listitem').first()).toContainText('Power Supply')

    await expect(
      page.getByText(/the evidence above is what this finding was generated from/i),
    ).toBeVisible()
  })

  test('states a time window when the evidence supports one, and explains its absence when not', async ({
    page,
  }) => {
    expect(withWindow().window).toBe(40)
    expect(withoutWindow().window).toBeNull()

    await signIn(page, 'administrator')

    await goto(page, `${LIST}/${withWindow().uuid}`)
    await expect(page.getByText('40 days', { exact: true })).toBeVisible()

    await goto(page, `${LIST}/${withoutWindow().uuid}`)
    await expect(page.getByText('Not stated', { exact: true })).toBeVisible()
    await expect(page.getByText(/fewer than three occurrences/i)).toBeVisible()
    await expect(page.getByText('40 days', { exact: true })).toHaveCount(0)
  })

  test('says the probability is unavailable rather than inventing one', async ({ page }) => {
    await signIn(page, 'administrator')
    await goto(page, `${LIST}/${withWindow().uuid}`)

    await expect(page.getByText('No calibrated failure model exists yet.')).toBeVisible()
  })

  test('is told about a finding in the notification centre, and follows the link', async ({
    page,
  }) => {
    await signIn(page, 'administrator')
    await goto(page, '/app/notifications')

    const notification = page
      .getByRole('listitem')
      .filter({ hasText: /predictive maintenance: E2E-PC-001/i })
      .first()

    await expect(notification).toBeVisible()
    await expect(notification.getByRole('link', { name: /^Open/ })).toHaveAttribute(
      'href',
      /^\/app\/predictions\/[0-9a-f-]{36}$/,
    )
  })

  test('confirms a finding only after the dialog is accepted, and the verdict replaces the buttons', async ({
    page,
  }) => {
    await signIn(page, 'administrator')
    await goto(page, `${LIST}/${withWindow().uuid}`)

    await page.getByRole('button', { name: 'Confirm finding' }).click()

    const dialog = page.getByRole('dialog')
    await expect(dialog).toContainText(/does not schedule a repair or change the machine/i)

    // The first click only asks: nothing has been decided yet.
    expect(await stateOf(page, withWindow().uuid)).toBe('pending')

    await dialog.getByRole('button', { name: 'Confirm finding' }).click()
    await settle(page)

    await expect(page.getByRole('button', { name: 'Confirm finding' })).toHaveCount(0)
    await expect(
      page.getByText(/decisions on a predictive-maintenance finding are final/i),
    ).toBeVisible()
    expect(await stateOf(page, withWindow().uuid)).toBe('confirmed')
  })

  test('moves a decided finding out of "Needs a decision" and into its own tab', async ({
    page,
  }) => {
    await signIn(page, 'administrator')
    await goto(page, LIST)

    await expect(page.getByRole('link', { name: new RegExp(withWindow().issue) })).toHaveCount(0)

    await page.getByRole('tab', { name: /confirmed/i }).click()
    await settle(page)
    await expect(page.getByRole('link', { name: new RegExp(withWindow().issue) })).toBeVisible()
  })

  test('dismisses another finding, and refuses a second verdict on it', async ({ page }) => {
    await signIn(page, 'administrator')
    await goto(page, `${LIST}/${withoutWindow().uuid}`)

    await page.getByRole('button', { name: 'Dismiss', exact: true }).click()
    await page.getByRole('dialog').getByRole('button', { name: 'Dismiss finding' }).click()
    await settle(page)

    expect(await stateOf(page, withoutWindow().uuid)).toBe('dismissed')

    // The server, not the page, is what refuses: a stale tab or a script that
    // decides again gets a 422 and the first verdict stands.
    const again = await pageJson(
      page,
      `/api/admin/predictions/${withoutWindow().uuid}/confirm`,
      'PATCH',
    )

    expect(again.status).toBe(422)
    expect(await stateOf(page, withoutWindow().uuid)).toBe('dismissed')
  })

  test('deciding opened no ticket and scheduled no visit', async ({ page }) => {
    await signIn(page, 'administrator')

    // The fixture PC has exactly the maintenance the fixtures gave it: three
    // completed repairs and the one scanned job. Both verdicts have been given
    // by now, and a verdict is a record of a human decision — not an action —
    // so the count has not moved. (The backend suite counts the rows directly;
    // this proves the page did not quietly offer to create one either.)
    const directory = await pageJson<{ meta: { total: number } }>(
      page,
      `/api/admin/maintenance?pc_unit=${manifest().pc_unit.uuid}&per_page=50`,
    )

    expect(directory.status).toBe(200)
    expect(directory.data.meta.total).toBe(4)
  })
})

for (const role of ['technician', 'teacher'] as const) {
  test.describe(`predictive maintenance — ${role}`, () => {
    test('is refused the list, every finding and both decision endpoints', async ({ page }) => {
      await signIn(page, role)

      expect((await apiFetch(page, '/api/admin/predictions')).status).toBe(403)

      for (const uuid of [withWindow().uuid, withoutWindow().uuid]) {
        expect((await apiFetch(page, `/api/admin/predictions/${uuid}`)).status).toBe(403)
        expect(
          (await pageJson(page, `/api/admin/predictions/${uuid}/confirm`, 'PATCH')).status,
        ).toBe(403)
        expect(
          (await pageJson(page, `/api/admin/predictions/${uuid}/dismiss`, 'PATCH')).status,
        ).toBe(403)
      }
    })

    test('cannot tell a real finding from one that does not exist', async ({ page }) => {
      await signIn(page, role)

      const real = await apiFetch(page, `/api/admin/predictions/${withWindow().uuid}`)
      const unknown = await apiFetch(page, `/api/admin/predictions/${UNKNOWN_UUID}`)

      // Same status, and neither reveals anything about the finding.
      expect(real.status).toBe(403)
      expect(unknown.status).toBe(403)
      expect(real.body).not.toContain(withWindow().issue)
      expect(real.body).not.toContain(withWindow().uuid)
    })

    test('meets the Forbidden page at the address, and no navigation entry for it', async ({
      page,
    }) => {
      await signIn(page, role)

      await expect(
        page
          .getByRole('navigation', { name: 'Primary' })
          .getByRole('link', { name: /predictive maintenance/i }),
      ).toHaveCount(0)

      await goto(page, LIST)
      await expect(page.getByRole('heading', { name: /access denied/i })).toBeVisible()
      await expect(page.getByText(withWindow().issue)).toHaveCount(0)

      await goto(page, `${LIST}/${withWindow().uuid}`)
      await expect(page.getByRole('heading', { name: /access denied/i })).toBeVisible()
      await expect(page.getByText(withWindow().issue)).toHaveCount(0)
    })

    test('is never told about a finding', async ({ page }) => {
      await signIn(page, role)

      const inbox = await pageJson<{ data: { topic: string | null }[] }>(
        page,
        '/api/notifications?per_page=50',
      )

      expect(inbox.status).toBe(200)
      expect(inbox.data.data.some((row) => row.topic === 'maintenance.prediction_generated')).toBe(
        false,
      )

      await signOut(page)
    })
  })
}

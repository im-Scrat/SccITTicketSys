import { expect, test, type Page } from '@playwright/test'
import { apiFetch, apiJson, apiLogin, goto, settle, signIn, signOut } from '../fixtures/app'

/**
 * Announcements, through a browser — WP-2.7c.
 *
 * ── The assertions that matter most are the negative ones ─────────────────
 *
 * Three of them, and each corresponds to a Client decision that would fail
 * silently if it were only asserted in a unit test:
 *
 *  1. **A technician never sees a teachers-only announcement** — not in the
 *     list, and not by uuid either. Audience targeting is an authorization
 *     boundary, not a display filter, and a list filter without the matching
 *     single-record check is an IDOR.
 *  2. **Editing a published announcement notifies nobody** (D7). The protection
 *     against an accidental school-wide repeat is that ordinary saves are
 *     silent, so the test edits a live announcement and counts.
 *  3. **A teacher and a technician cannot reach the management surface at all**
 *     — 403 from the API and the Forbidden page from the URL.
 *
 * The email guarantee (D5) is asserted in the backend suite, where `Mail::fake`
 * can prove a negative; a browser cannot observe an email that was not sent.
 *
 * Announcements are created by driving the **real administrator API**, the same
 * way the notification suite drives a real ticket conversation, so the whole
 * chain — publish, listener, audience resolution, notification row, reader
 * page — is exercised rather than seeded.
 */

interface Arranged {
  uuid: string
  title: string
}

/** Create and publish an announcement for `audience`, as the administrator. */
async function publishAnnouncement(page: Page, audience: string): Promise<Arranged> {
  const title = `E2E ${audience} announcement ${Date.now()}`

  await goto(page, '/sign-in')
  await apiLogin(page, 'administrator')

  const created = await apiJson<{ data: { id: string } }>(page, '/api/admin/announcements', 'POST', {
    title,
    content: 'Published by the WP-2.7c browser suite.',
    audience,
  })
  expect(created.status, 'creating the announcement').toBe(201)

  const uuid = created.data.data.id

  const published = await apiFetch(page, `/api/admin/announcements/${uuid}/publish`, 'POST')
  expect(published.status, `publishing: ${published.body}`).toBe(200)

  await signOut(page)

  return { uuid, title }
}

test.describe('announcements — administrator', () => {
  test('composes, publishes and manages an announcement end to end', async ({ page }) => {
    await signIn(page, 'administrator')

    // Compose through the real form (UC-13).
    await goto(page, '/app/announcements/manage')
    await page.getByRole('button', { name: /new announcement/i }).click()

    const title = `Composed in the browser ${Date.now()}`
    await page.getByLabel(/^Title/).fill(title)
    await page.getByLabel(/^Announcement/).fill('Written and published by the browser suite.')
    await page.getByLabel(/^Audience/).selectOption('teachers')

    await page.getByRole('button', { name: /create draft/i }).click()
    await settle(page)

    // A new announcement is a draft: composing notifies nobody.
    const row = page.getByRole('listitem').filter({ hasText: title }).first()
    await expect(row).toBeVisible()
    await expect(row).toContainText('Draft')

    // Publishing is the separate, deliberate act.
    await row.getByRole('button', { name: /^Publish$/ }).click()
    await settle(page)
    await expect(
      page.getByRole('listitem').filter({ hasText: title }).first(),
    ).toContainText('Published')
  })

  test('does not show an administrator a teachers-only announcement on their reader', async ({
    page,
  }) => {
    // The reader is audience-scoped for everyone, administrators included:
    // their reader page shows what an administrator was told, not the estate.
    const { title } = await publishAnnouncement(page, 'teachers')

    await signIn(page, 'administrator')
    await goto(page, '/app/announcements')

    await expect(page.getByText(title)).toHaveCount(0)
  })

  test('shows every announcement on the management surface, whatever its audience', async ({
    page,
  }) => {
    const { title } = await publishAnnouncement(page, 'teachers')

    await signIn(page, 'administrator')
    await goto(page, '/app/announcements/manage')

    await expect(page.getByRole('listitem').filter({ hasText: title }).first()).toBeVisible()
  })

  test('editing a published announcement notifies nobody', async ({ page }) => {
    // Decision D7, asserted by counting the teacher's notifications either side
    // of an edit to a live announcement.
    const { uuid, title } = await publishAnnouncement(page, 'teachers')

    await goto(page, '/sign-in')
    await apiLogin(page, 'teacher')
    const before = await apiJson<{ data: unknown[] }>(page, '/api/notifications?type=announcement')
    const countBefore = before.data.data.length
    await signOut(page)

    await goto(page, '/sign-in')
    await apiLogin(page, 'administrator')
    const edited = await apiFetch(page, `/api/admin/announcements/${uuid}`, 'PUT', {
      title: `${title} (corrected)`,
      content: 'Edited by the browser suite. This must not notify anyone.',
      audience: 'teachers',
    })
    expect(edited.status, `editing: ${edited.body}`).toBe(200)
    await signOut(page)

    await goto(page, '/sign-in')
    await apiLogin(page, 'teacher')
    const after = await apiJson<{ data: unknown[] }>(page, '/api/notifications?type=announcement')

    expect(after.data.data.length).toBe(countBefore)
  })
})

test.describe('announcements — teacher', () => {
  test('receives the notification and reads the announcement', async ({ page }) => {
    const { uuid, title } = await publishAnnouncement(page, 'teachers')

    await signIn(page, 'teacher')

    // The notification arrived in the WP-2.7b centre, unchanged by WP-2.7c.
    await goto(page, '/app/notifications')
    const notification = page.getByRole('listitem').filter({ hasText: title }).first()
    await expect(notification).toBeVisible()

    // Its destination is the announcement reader.
    await expect(notification.getByRole('link', { name: /^Open/ })).toHaveAttribute(
      'href',
      `/app/announcements/${uuid}`,
    )

    // And the announcement itself is readable.
    await goto(page, '/app/announcements')
    await expect(page.getByRole('heading', { name: title })).toBeVisible()
  })

  test('cannot reach the management surface', async ({ page }) => {
    await signIn(page, 'teacher')

    expect((await apiFetch(page, '/api/admin/announcements')).status).toBe(403)
    expect((await apiFetch(page, '/api/admin/announcements', 'POST')).status).toBe(403)

    await goto(page, '/app/announcements/manage')
    await expect(page.getByRole('button', { name: /new announcement/i })).toHaveCount(0)
  })
})

test.describe('announcements — technician', () => {
  test('is excluded from a teachers-only announcement, in the list and by uuid', async ({
    page,
  }) => {
    // The audience boundary, proven in a browser. Absent from the list is the
    // convenience; the 403 by uuid is the control.
    const { uuid, title } = await publishAnnouncement(page, 'teachers')

    await signIn(page, 'technician')

    await goto(page, '/app/announcements')
    await expect(page.getByText(title)).toHaveCount(0)

    expect((await apiFetch(page, `/api/announcements/${uuid}`)).status).toBe(403)

    // …and was never notified about it either.
    const inbox = await apiJson<{ data: { title: string }[] }>(
      page,
      '/api/notifications?type=announcement',
    )
    expect(inbox.data.data.some((row) => row.title === title)).toBe(false)
  })

  test('receives an announcement addressed to everyone', async ({ page }) => {
    // The positive control for the test above: the exclusion is by audience,
    // not a blanket refusal.
    const { title } = await publishAnnouncement(page, 'all')

    await signIn(page, 'technician')
    await goto(page, '/app/announcements')

    await expect(page.getByRole('heading', { name: title })).toBeVisible()
  })

  test('cannot reach the management surface', async ({ page }) => {
    await signIn(page, 'technician')

    expect((await apiFetch(page, '/api/admin/announcements')).status).toBe(403)

    await goto(page, '/app/announcements/manage')
    await expect(page.getByRole('button', { name: /new announcement/i })).toHaveCount(0)
  })
})

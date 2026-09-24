import { expect, test, type Page } from '@playwright/test'
import { apiFetch, apiLogin, goto, json, settle, signIn, signOut } from '../fixtures/app'
import { manifest } from '../fixtures/manifest'

/**
 * The notification experience, through a browser — WP-2.7b.
 *
 * ── Where the notifications come from ──────────────────────────────────────
 *
 * Not from a seeder. `sccit:e2e-fixtures` writes no notifications, and the
 * Client's decision (Q6) was to **drive real workflows** rather than extend a
 * fixture that four other suites depend on. So each run performs an actual
 * ticket conversation — a teacher reports a fault, an administrator assigns it,
 * the technician comments, the administrator adds an internal note — and the
 * notifications under test are the ones that chain genuinely produced.
 *
 * That is worth more than seeded rows. It proves the whole path: an event fires,
 * a listener resolves an audience, a preference gate lets it through, a row is
 * written, an endpoint scopes it to its owner, and a browser renders it. A
 * seeded row would prove only the last step.
 *
 * ── The two assertions that matter most ────────────────────────────────────
 *
 * The teacher spec carries them, and both are about what a person must **not**
 * see:
 *
 *  1. **An internal note produces no notification for the requester.** Not a
 *     redacted one — none at all, because "there is a comment you cannot read"
 *     is itself the disclosure `is_internal` exists to prevent. The arrangement
 *     posts two public comments and one internal one, so the teacher having
 *     exactly two comment notifications is the proof.
 *  2. **One user cannot reach another user's notification by uuid.** Asserted
 *     against a uuid that genuinely belongs to the technician, on both the read
 *     and the write endpoint — a list filter without the matching single-record
 *     check is an IDOR.
 */

interface Arranged {
  ticketUuid: string
  ticketNumber: string
}

/**
 * Run the ticket conversation that generates this run's notifications.
 *
 * Arrangement only — see `apiLogin`. Every assertion afterwards signs in
 * through the real form and reads the rendered surface.
 */
async function arrangeConversation(page: Page): Promise<Arranged> {
  const { users } = manifest()

  await goto(page, '/sign-in')

  // 1. The teacher reports a fault. Their own report notifies nobody.
  await apiLogin(page, 'teacher')
  const created = await apiFetch(page, '/api/tickets', 'POST', {
    title: 'Projector in the verification lab will not power on',
    description:
      'The projector shows no light and the power indicator stays dark after a full reset.',
    category: 'hardware',
  })
  expect(created.status, `creating the ticket: ${created.body}`).toBe(201)

  const ticket = json<{ data: { id: string; ticket_number: string } }>(created).data

  // 2. The administrator assigns it — T1a to the technician, T2 to the reporter.
  await apiLogin(page, 'administrator')
  const assigned = await apiFetch(page, `/api/admin/tickets/${ticket.id}/assign`, 'POST', {
    technician: users.technician.uuid,
  })
  expect(assigned.status, `assigning the ticket: ${assigned.body}`).toBe(200)

  // 3. The technician comments in public — T3 to the reporter.
  await apiLogin(page, 'technician')
  const publicComment = await apiFetch(page, `/api/tickets/${ticket.id}/comments`, 'POST', {
    body: 'Checked the power supply on site; ordering a replacement lamp module.',
  })
  expect(publicComment.status, `public comment: ${publicComment.body}`).toBe(201)

  // 4. The administrator adds an INTERNAL note — staff only. The teacher must
  //    get nothing from this, which is what step 6 counts.
  await apiLogin(page, 'administrator')
  const internal = await apiFetch(page, `/api/tickets/${ticket.id}/comments`, 'POST', {
    body: 'Internal: charge this to the projector maintenance budget, not the lab’s.',
    is_internal: true,
  })
  expect(internal.status, `internal comment: ${internal.body}`).toBe(201)

  // 5. A second public comment — reaches the administrator too, who is now a
  //    prior participant. That is the administrator's notification for this run.
  await apiLogin(page, 'technician')
  const followUp = await apiFetch(page, `/api/tickets/${ticket.id}/comments`, 'POST', {
    body: 'The replacement module arrives tomorrow morning.',
  })
  expect(followUp.status, `follow-up comment: ${followUp.body}`).toBe(201)

  await signOut(page)

  return { ticketUuid: ticket.id, ticketNumber: ticket.ticket_number }
}

/** The bell in the header, whatever its current count. */
function bell(page: Page) {
  return page.getByRole('button', { name: /^Notifications/ })
}

test.describe('notification centre — administrator', () => {
  test('sees a workflow notification, acts on it, and sets a preference that persists', async ({
    page,
  }) => {
    const { ticketNumber } = await arrangeConversation(page)

    await signIn(page, 'administrator')

    // The badge states its count in the accessible name, not only in pixels.
    await expect(bell(page)).toHaveAccessibleName(/Notifications, \d+ unread/)

    // The panel opens from the header and carries the notification the
    // conversation produced.
    await bell(page).click()
    const panel = page.getByRole('dialog', { name: 'Notifications' })
    await expect(panel).toBeVisible()
    await expect(panel.getByText(`New comment on ticket ${ticketNumber}`).first()).toBeVisible()

    // …and leads to the full centre.
    await panel.getByRole('link', { name: /see all notifications/i }).click()
    await expect(page).toHaveURL(/\/app\/notifications$/)
    await settle(page)

    const row = page.getByRole('listitem').filter({ hasText: ticketNumber }).first()
    await expect(row).toBeVisible()

    // Following the notification lands on the record it is about.
    await row.getByRole('link', { name: /^Open/ }).click()
    await expect(page).toHaveURL(/\/app\/tickets\//)
    await settle(page)

    /*
     * Clearing the inbox clears the badge.
     *
     * Above twenty unread the action asks first, and on a stack that has been
     * running this suite for a while the administrator is comfortably above
     * twenty — so the confirmation is answered when it appears rather than
     * assumed away. Asserting only the immediate path made this test depend on
     * how many times it had been run before.
     */
    await goto(page, '/app/notifications')
    await page.getByRole('button', { name: /mark all as read/i }).click()

    const confirmation = page.getByRole('dialog')
    if (await confirmation.isVisible().catch(() => false)) {
      await confirmation.getByRole('button', { name: /mark all as read/i }).click()
    }
    await expect(bell(page)).toHaveAccessibleName('Notifications', { timeout: 20_000 })
    expect(json<{ unread: number }>(await apiFetch(page, '/api/notifications/unread-count')).unread).toBe(0)

    // A preference change survives a reload, because it was actually saved.
    await goto(page, '/app/account')
    const emailAssignment = page.getByRole('checkbox', {
      name: 'Email notifications for Assignment',
    })
    await expect(emailAssignment).toBeChecked()
    await emailAssignment.uncheck()
    await page.getByRole('button', { name: /save preferences/i }).click()
    await expect(page.getByText(/preferences have been saved/i)).toBeVisible()

    await goto(page, '/app/account')
    await expect(
      page.getByRole('checkbox', { name: 'Email notifications for Assignment' }),
    ).not.toBeChecked()

    // Put it back, so a re-run starts from the documented default.
    await page.getByRole('checkbox', { name: 'Email notifications for Assignment' }).check()
    await page.getByRole('button', { name: /save preferences/i }).click()
    await expect(page.getByText(/preferences have been saved/i)).toBeVisible()
  })

  test('cannot switch off the lockout security email, and is told why', async ({ page }) => {
    await signIn(page, 'administrator')
    await goto(page, '/app/account')

    const forced = page.getByRole('checkbox', {
      name: /Email notifications for System — always on/,
    })
    await expect(forced).toBeDisabled()
    await expect(forced).toBeChecked()
    await expect(page.getByText(/security notices.*always emailed/i)).toBeVisible()
  })
})

test.describe('notification centre — technician', () => {
  test('is notified of an assignment, opens the ticket, and unread filtering follows', async ({
    page,
  }) => {
    const { ticketNumber } = await arrangeConversation(page)

    await signIn(page, 'technician')
    await expect(bell(page)).toHaveAccessibleName(/Notifications, \d+ unread/)

    await goto(page, '/app/notifications')

    const assignment = page
      .getByRole('listitem')
      .filter({ hasText: `Ticket ${ticketNumber} assigned to you` })
      .first()
    await expect(assignment).toBeVisible()
    // Unread is stated in words, not only in colour (NFR-ACC-004).
    await expect(assignment).toContainText('Unread.')

    // Following it lands on the ticket the notification names.
    await assignment.getByRole('link', { name: /^Open/ }).click()
    await expect(page).toHaveURL(/\/app\/tickets\//)
    await settle(page)

    // Following it also marked it read — so the unread filter no longer has it,
    // while the full list still does.
    await goto(page, '/app/notifications')
    await page.getByRole('tab', { name: /unread/i }).click()
    await settle(page)
    await expect(
      page.getByRole('listitem').filter({ hasText: `Ticket ${ticketNumber} assigned to you` }),
    ).toHaveCount(0)

    await page.getByRole('tab', { name: /^All$/ }).click()
    await settle(page)
    const read = page
      .getByRole('listitem')
      .filter({ hasText: `Ticket ${ticketNumber} assigned to you` })
      .first()
    await expect(read).toBeVisible()
    await expect(read).toContainText('Read.')

    // And it can be put back — read state is reversible, not a one-way door.
    await read.getByRole('button', { name: /mark as unread/i }).click()
    await expect(
      page
        .getByRole('listitem')
        .filter({ hasText: `Ticket ${ticketNumber} assigned to you` })
        .first(),
    ).toContainText('Unread.')
  })

  test('filters by type through the server', async ({ page }) => {
    const { ticketNumber } = await arrangeConversation(page)

    await signIn(page, 'technician')
    await goto(page, '/app/notifications')

    await page.getByLabel(/filter by type/i).selectOption('assignment')
    await settle(page)

    // The assignment survives the filter; the comment on the same ticket does not.
    await expect(
      page.getByRole('listitem').filter({ hasText: `Ticket ${ticketNumber} assigned to you` }),
    ).toHaveCount(1)
    await expect(
      page.getByRole('listitem').filter({ hasText: `New comment on ticket ${ticketNumber}` }),
    ).toHaveCount(0)
  })
})

test.describe('notification centre — teacher', () => {
  test('sees the updates on their own ticket but never the internal note', async ({ page }) => {
    const { ticketUuid, ticketNumber } = await arrangeConversation(page)

    await signIn(page, 'teacher')
    await goto(page, '/app/notifications')

    // The status change reached them.
    await expect(
      page.getByRole('listitem').filter({ hasText: `Ticket ${ticketNumber} is now` }).first(),
    ).toBeVisible()

    /*
     * The heart of this suite.
     *
     * Three comments were posted on this ticket — two public, one internal. A
     * teacher must have been told about exactly the two, because the *existence*
     * of an internal note is itself staff-only information.
     */
    await expect(
      page.getByRole('listitem').filter({ hasText: `New comment on ticket ${ticketNumber}` }),
    ).toHaveCount(2)

    // Asserted against the API as well as the surface: a UI that merely hid the
    // third row would look identical here.
    const own = json<{ data: { title: string; action_url: string | null }[] }>(
      await apiFetch(page, `/api/notifications?type=ticket_update`),
    )
    const aboutThisTicket = own.data.filter((row) => row.title.includes(ticketNumber))
    expect(aboutThisTicket.filter((row) => row.title.startsWith('New comment'))).toHaveLength(2)

    // Every destination they were given is inside the application they can reach.
    for (const row of aboutThisTicket) {
      expect(row.action_url).toBe(`/app/tickets/${ticketUuid}`)
    }
  })

  test('cannot reach another user’s notification by uuid', async ({ page }) => {
    await arrangeConversation(page)

    // Take a uuid that genuinely belongs to the technician.
    await goto(page, '/sign-in')
    await apiLogin(page, 'technician')
    const technicianInbox = json<{ data: { id: string }[] }>(
      await apiFetch(page, '/api/notifications'),
    )
    const foreign = technicianInbox.data[0]?.id
    expect(foreign, 'the technician should have a notification to borrow').toBeTruthy()
    await signOut(page)

    await signIn(page, 'teacher')

    // Absent from the list…
    const teacherInbox = json<{ data: { id: string }[] }>(await apiFetch(page, '/api/notifications'))
    expect(teacherInbox.data.map((row) => row.id)).not.toContain(foreign)

    // …and equally unreachable by uuid, on both the read and the write endpoint.
    // A list filter without this check is an IDOR.
    expect((await apiFetch(page, `/api/notifications/${foreign}`)).status).toBe(403)
    expect((await apiFetch(page, `/api/notifications/${foreign}/read`, 'PATCH')).status).toBe(403)
    expect((await apiFetch(page, `/api/notifications/${foreign}/unread`, 'PATCH')).status).toBe(403)
  })

  test('reaches the notification centre and its own preferences without a permission', async ({
    page,
  }) => {
    // Neither surface is gated: every authenticated user has notifications, and
    // no `notifications.*` permission exists (OD-4).
    await signIn(page, 'teacher')

    await goto(page, '/app/notifications')
    await expect(page.getByRole('heading', { name: 'Notifications', level: 1 })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Access denied' })).toHaveCount(0)

    await goto(page, '/app/account')
    await expect(page.getByRole('checkbox', { name: /In App notifications for/ }).first()).toBeVisible()
  })
})

test.describe('the notification cache does not outlive the session', () => {
  test('signing out clears the cached inbox before the next principal renders', async ({
    page,
  }) => {
    const { ticketNumber } = await arrangeConversation(page)

    await signIn(page, 'technician')
    await goto(page, '/app/notifications')
    await expect(
      page.getByRole('listitem').filter({ hasText: `Ticket ${ticketNumber} assigned to you` }),
    ).toHaveCount(1)

    /*
     * Sign out through the application's own control rather than by clearing
     * cookies, because the thing under test is the *client-side* eviction in
     * `useLogout`. Clearing cookies would end the session without ever running
     * it, and the test would pass while the cache still held the previous
     * person's inbox — which on a shared machine is a real disclosure.
     */
    await page.locator('header summary').click()
    await page.getByRole('button', { name: /sign out/i }).click()
    await expect(page).toHaveURL(/\/sign-in/, { timeout: 30_000 })
    await settle(page)

    await signIn(page, 'teacher')
    await goto(page, '/app/notifications')

    // The previous principal's assignment must not be rendered from cache, not
    // even for the instant before the refetch lands.
    await expect(
      page.getByRole('listitem').filter({ hasText: `Ticket ${ticketNumber} assigned to you` }),
    ).toHaveCount(0)
  })
})

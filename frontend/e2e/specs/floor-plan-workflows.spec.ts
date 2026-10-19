import { expect, test, type Locator, type Page } from '@playwright/test'
import { apiJson, apiLogin, goto, signIn } from '../fixtures/app'
import { manifest } from '../fixtures/manifest'

/**
 * The Floorplan's end-to-end workflows — Phase 2.11 (SDD DD-100..DD-104).
 *
 * `floor-plan.spec.ts` proves the editor's core moves and its refusals against
 * the seeded fixture room. This file walks the longer journeys an
 * administrator actually takes: a new room from nothing to a saved plan, a PC
 * brought in from outside with an explicit confirmation, fixtures sized and
 * bounded, undo/redo, the leave warning and draft recovery, the Details panel's
 * real records, and status changes that tickets make while the plan is open.
 *
 * Isolation: the journeys that change data work on a room and a PC each run
 * creates for itself (unique codes per run), so they never move the fixture
 * room's units — the status-coverage assertions in `floor-plan.spec.ts` and the
 * accessibility audit depend on those. The fixture-room journeys only read or
 * leave unsaved drafts that they discard.
 */

const fixtureRoom = () => manifest().room
const stamp = () => Date.now().toString(36).toUpperCase()

const node = (page: Page, name: string): Locator =>
  page.locator(`[data-testid="floor-plan-node"][aria-label^="${name},"]`)

const fixtures = (page: Page): Locator => page.locator('[data-testid="floor-plan-fixture"]')

const details = (page: Page): Locator => page.getByRole('region', { name: 'Details' }).first()

async function labelOf(locator: Locator): Promise<string> {
  return (await locator.getAttribute('aria-label')) ?? ''
}

test.describe('Floorplan — overview', () => {
  test('filters the room cards by search and by set-up state', async ({ page }) => {
    await signIn(page, 'administrator')
    await goto(page, '/app/floor-plan')
    const cards = page.getByRole('list', { name: 'Rooms' })
    const room = fixtureRoom()

    await page.getByPlaceholder('Search rooms by name or code').fill(room.name)
    await expect(cards.getByRole('link', { name: new RegExp(room.name) })).toBeVisible()

    // The fixture room has a Floorplan, so "No Floorplan yet" must drop it.
    const setUp = page.getByRole('combobox', { name: 'Floorplan' })
    await setUp.selectOption('not_set_up')
    await expect(cards.getByRole('link', { name: new RegExp(room.name) })).toHaveCount(0)

    await setUp.selectOption('set_up')
    await expect(cards.getByRole('link', { name: new RegExp(room.name) })).toBeVisible()
  })
})

test.describe('Floorplan — a new room, from nothing to a saved plan', () => {
  test('create the room, set it up, move a PC in, place it, add and size a fixture, save', async ({
    page,
  }) => {
    test.setTimeout(120_000)
    const id = stamp()
    const roomName = `E2E Workflow Room ${id}`
    const pcName = `E2E Workflow PC ${id}`

    // A PC with no room, made through the API — the fixture to bring in.
    await goto(page, '/sign-in')
    await apiLogin(page, 'administrator')
    const created = await apiJson<{ data: { id: string } }>(page, '/api/admin/pc-units', 'POST', {
      unit_code: `E2E-WF-${id}`,
      pc_name: pcName,
      status: 'available',
    })
    expect(created.status, 'creating the PC').toBe(201)

    await signIn(page, 'administrator')
    await goto(page, '/app/floor-plan')

    // 1. New room, through Locations' own form, opened from the Floorplan.
    await page.getByRole('button', { name: 'New room' }).click()
    const drawer = page.getByRole('dialog', { name: 'New room' })
    await drawer.getByLabel('Building').selectOption({ label: 'E2E Verification Building' })
    await drawer.getByLabel('Floor').selectOption({ label: 'E2E Floor' })
    await drawer.getByLabel('Name').fill(roomName)
    await drawer.getByLabel('Code').fill(`E2E-WF-${id}`)
    await drawer.getByLabel('Type').selectOption('laboratory')
    await drawer.getByRole('button', { name: 'Create room' }).click()

    // 2. Straight into its editor, which offers the setup.
    await expect(page.getByRole('heading', { level: 1, name: roomName })).toBeVisible()
    await page.getByRole('radio', { name: /Small room/ }).check()
    await page.getByRole('button', { name: 'Set up Floorplan' }).click()
    await expect(page.getByRole('button', { name: 'Add fixture' })).toBeVisible()

    // 3. Bring the PC in from outside — never silently: a named confirmation.
    await page.getByRole('tab', { name: 'Other rooms' }).click()
    await page.getByPlaceholder('Search all PCs').fill(pcName)
    await page.getByRole('button', { name: `Move ${pcName} here` }).click()
    const confirm = page.getByRole('dialog', { name: `Move ${pcName} to ${roomName}?` })
    await expect(confirm).toContainText('It has no room yet')
    await confirm.getByRole('button', { name: 'Move PC' }).click()
    await expect(confirm).toBeHidden()

    // 4. Place it from this room's list.
    await page.getByRole('tab', { name: /In this room/ }).click()
    await page.getByRole('button', { name: `Place ${pcName}` }).click()
    const pc = node(page, pcName)
    await expect(pc).toBeVisible()
    const placedAt = await labelOf(pc)

    // 5. Turn it with R, undo, redo.
    await pc.focus()
    await page.keyboard.press('Enter')
    await page.keyboard.press('r')
    await expect(pc).toHaveAttribute('aria-label', /turned 90°/)
    await page.keyboard.press('Control+z')
    await expect(pc).toHaveAttribute('aria-label', placedAt)
    await page.keyboard.press('Control+Shift+z')
    await expect(pc).toHaveAttribute('aria-label', /turned 90°/)

    // 6. A desk from the "Add fixture" menu, sized from the Details panel.
    await page.getByRole('button', { name: 'Add fixture' }).click()
    await page
      .getByRole('group', { name: 'Add a fixture' })
      .getByRole('button', { name: 'Desk' })
      .click()
    await expect(fixtures(page)).toHaveCount(1)
    const width = details(page).getByLabel('Width')
    await width.fill('200')
    await width.press('Enter')
    await expect(fixtures(page).first()).toHaveAttribute('aria-label', /^Desk, 200 by/)

    // 7. Out of bounds is refused: a 200-wide desk centred 10 units from the
    // 800-wide room's right wall would stick out, so it stays where it was.
    const sized = await labelOf(fixtures(page).first())
    const x = details(page).getByLabel('X', { exact: true })
    await x.fill('790')
    await x.press('Enter')
    await expect(fixtures(page).first()).toHaveAttribute('aria-label', sized)
    await expect(x).not.toHaveValue('790')

    // 8. Save, then a reload shows exactly what was saved.
    await page.getByRole('button', { name: 'Save', exact: true }).click()
    await expect(page.getByRole('status').filter({ hasText: /^Saved/ })).toBeVisible()
    const savedPc = await labelOf(pc)
    const savedDesk = await labelOf(fixtures(page).first())

    await page.reload()
    await expect(node(page, pcName)).toHaveAttribute('aria-label', savedPc)
    expect(savedPc).toMatch(/turned 90°/)
    // In view mode fixtures are scenery (no name, no tab stop); edit mode
    // makes them interactive, and named, again.
    await expect(fixtures(page)).toHaveCount(1)
    await page.getByRole('button', { name: 'Edit layout' }).click()
    await expect(fixtures(page).first()).toHaveAttribute('aria-label', savedDesk)
  })

  test('a ticket on a placed PC turns it Pending Repair live, and resolving it turns it back', async ({
    page,
    browser,
  }) => {
    test.setTimeout(120_000)
    const id = stamp()
    const pcName = `E2E Status PC ${id}`
    const room = fixtureRoom()

    // A PC of its own in the fixture room, so the seeded units keep their statuses.
    await goto(page, '/sign-in')
    await apiLogin(page, 'administrator')
    const created = await apiJson<{ data: { id: string } }>(page, '/api/admin/pc-units', 'POST', {
      unit_code: `E2E-ST-${id}`,
      pc_name: pcName,
      status: 'available',
      room: room.uuid,
    })
    expect(created.status, 'creating the PC').toBe(201)
    const pcUuid = created.data.data.id

    // The administrator has the room open, with the PC listed.
    await signIn(page, 'administrator')
    await goto(page, `/app/floor-plan/rooms/${room.uuid}`)
    const row = page.getByRole('list', { name: 'PCs in this room' }).getByText(pcName)
    await expect(row).toBeVisible()

    // A teacher reports a fault on it, from another browser.
    const teacher = await browser.newContext()
    const teacherPage = await teacher.newPage()
    try {
      await goto(teacherPage, '/sign-in')
      await apiLogin(teacherPage, 'teacher')
      const ticket = await apiJson<{ data: { id: string } }>(teacherPage, '/api/tickets', 'POST', {
        title: `Workstation ${id} will not boot`,
        description: 'It stays on a black screen after the logo, every time it is switched on.',
        category: 'hardware',
        room: room.uuid,
        pc_unit: pcUuid,
      })
      expect(ticket.status, 'reporting the ticket').toBe(201)
      const ticketId = ticket.data.data.id

      // The open editor hears it without a reload.
      const statusFilter = page.getByLabel('Filter by status')
      await statusFilter.selectOption('pending_repair')
      await expect(
        page.getByRole('list', { name: 'PCs in this room' }).getByText(pcName),
      ).toBeVisible({ timeout: 15_000 })

      // The administrator moves it through the lifecycle to resolved.
      await apiLogin(teacherPage, 'administrator')
      for (const status of ['in-progress', 'resolved']) {
        const moved = await apiJson(teacherPage, `/api/tickets/${ticketId}/status`, 'PUT', {
          status,
        })
        expect(moved.status, `moving the ticket to ${status}`).toBe(200)
      }

      await statusFilter.selectOption('available')
      await expect(
        page.getByRole('list', { name: 'PCs in this room' }).getByText(pcName),
      ).toBeVisible({ timeout: 15_000 })
    } finally {
      await teacher.close()
      // Take it out of the fixture room so the next run starts as seeded.
      await apiLogin(page, 'administrator')
      await apiJson(page, `/api/admin/pc-units/${pcUuid}`, 'PUT', { room: null })
    }
  })
})

test.describe('Floorplan — unsaved work', () => {
  test('warns before leaving, and offers the draft back after a reload', async ({ page }) => {
    const room = fixtureRoom()
    await signIn(page, 'administrator')
    await goto(page, `/app/floor-plan/rooms/${room.uuid}`)
    await page.getByRole('button', { name: 'Edit layout' }).click()

    const unit = node(page, 'E2E Plan PC 2')
    await unit.focus()
    await page.keyboard.press('Enter')
    await page.keyboard.press('ArrowDown')
    await expect(page.getByText(/Unsaved changes \(1\)/)).toBeVisible()
    const moved = await labelOf(unit)

    // An in-app link is intercepted with a real choice.
    await page
      .getByRole('navigation', { name: 'Breadcrumb' })
      .getByRole('link', { name: 'Floorplan' })
      .click()
    const leave = page.getByRole('dialog', { name: 'Leave without saving?' })
    await expect(leave).toBeVisible()
    await leave.getByRole('button', { name: 'Stay' }).click()
    await expect(page).toHaveURL(new RegExp(`/app/floor-plan/rooms/${room.uuid}$`))

    // A reload asks the browser's own question, and the draft survives it.
    // Wait past the 400 ms debounce so the draft is in session storage.
    await page.waitForTimeout(700)
    const prompt = new Promise<string>((resolve) => {
      page.once('dialog', (dialog) => {
        resolve(dialog.type())
        void dialog.accept()
      })
    })
    await page.reload()
    expect(await prompt).toBe('beforeunload')

    const offer = page
      .getByRole('status')
      .filter({ hasText: 'You have unsaved changes from earlier' })
    await expect(offer).toBeVisible()
    await offer.getByRole('button', { name: 'Restore them' }).click()
    await expect(page.getByText(/Unsaved changes \(1\)/)).toBeVisible()
    await expect(node(page, 'E2E Plan PC 2')).toHaveAttribute('aria-label', moved)

    // Discarding leaves the seeded plan as it was — nothing was saved.
    await page.getByRole('button', { name: 'Discard' }).click()
    await expect(page.getByRole('status').filter({ hasText: /^Saved/ })).toBeVisible()
  })
})

test.describe('Floorplan — the Details panel shows the real records', () => {
  test('a PC opens its asset facts, status, and repair history', async ({ page }) => {
    const room = fixtureRoom()
    const pc = manifest().pc_unit
    await signIn(page, 'administrator')
    await goto(page, `/app/floor-plan/rooms/${room.uuid}`)

    await node(page, pc.pc_name).focus()
    await page.keyboard.press('Enter')

    const panel = details(page)
    await expect(panel.getByRole('heading', { name: pc.pc_name })).toBeVisible()
    await expect(panel).toContainText('E2E-AT-001')
    await expect(panel).toContainText('E2E-SN-00000001')
    await expect(panel).toContainText('E2E fixture — scanned job')
    await expect(panel.getByRole('link', { name: /Open asset record/ })).toHaveAttribute(
      'href',
      `/app/assets/pc-units/${pc.uuid}`,
    )
    // Status is the recorded repair status, never a claim about the network.
    await expect(panel).not.toContainText(/\b(Online|Offline)\b/)
  })
})

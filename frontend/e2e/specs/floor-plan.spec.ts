import { expect, test, type Page } from '@playwright/test'
import { apiFetch, apiJson, apiStatus, goto, signIn, signOut } from '../fixtures/app'
import { manifest } from '../fixtures/manifest'

/**
 * The Interactive Floor Plan, through a browser — Phase 2.8 / WP-C.
 *
 * The plan is **Administrator-only**, so the assertions that matter most are the
 * negative ones, and they are made against the real stack: the API's own status
 * codes for a *real* room uuid, and what the URL actually renders — not merely
 * whether a link is hidden. Per-user permission grants (the WP-B trap) are
 * covered where they can be arranged cheaply, in the Pest suite; this file
 * proves the boundary holds end to end for the three seeded roles.
 *
 * The fixture room carries an active layout with a unit in every PC status
 * (`sccit:e2e-fixtures`), so the map is exercised with all six shapes on it.
 */

const room = () => manifest().room
const mapUrl = () => `/app/floor-plan/rooms/${room().uuid}`
const apiUrl = () => `/api/admin/floor-plan/rooms/${room().uuid}`

test.describe('floor plan — administrator', () => {
  test('sees the map: grid, one node per unit, legend and text alternative', async ({ page }) => {
    await signIn(page, 'administrator')
    await goto(page, mapUrl())

    await expect(page.getByRole('heading', { name: room().name, level: 1 })).toBeVisible()
    await expect(page.getByTestId('floor-plan-svg')).toBeVisible()
    await expect(page.getByTestId('floor-plan-grid')).toBeAttached()

    // Six status units plus the fixture PC.
    await expect(page.getByTestId('floor-plan-node')).toHaveCount(7)

    // Status is written, not only coloured: every state's label is on the page.
    for (const label of [
      'Available',
      'Assigned',
      'Online',
      'Offline',
      'Under Maintenance',
      'Retired',
    ]) {
      await expect(page.getByRole('region', { name: 'Legend' }).getByText(label)).toBeVisible()
    }
    await expect(page.getByRole('table', { name: /Units placed in/ })).toBeVisible()
  })

  test('zooms, pans and resets with the toolbar and the keyboard', async ({ page }) => {
    await signIn(page, 'administrator')
    await goto(page, mapUrl())

    const svg = page.getByTestId('floor-plan-svg')
    const initial = await svg.getAttribute('viewBox')
    expect(initial).toBe('0 0 1000 600')

    await page.getByRole('button', { name: 'Zoom in' }).click()
    await expect(svg).not.toHaveAttribute('viewBox', initial!)
    await expect(page.getByRole('status').filter({ hasText: '125%' })).toBeVisible()

    await page.getByRole('button', { name: 'Pan right' }).click()
    const panned = await svg.getAttribute('viewBox')
    expect(panned).not.toBe(initial)

    // The same controls from the keyboard, on the focused map.
    await page.getByRole('group', { name: /^Floor plan of/ }).focus()
    await page.keyboard.press('0')
    await expect(svg).toHaveAttribute('viewBox', initial!)
  })

  test('dragging the background pans the view and leaves every unit where it is', async ({
    page,
  }) => {
    await signIn(page, 'administrator')
    await goto(page, mapUrl())

    const node = page.getByTestId('floor-plan-node').first()
    const before = await node.getAttribute('transform')
    const box = (await page.getByTestId('floor-plan-svg').boundingBox())!

    await page.mouse.move(box.x + 20, box.y + 20)
    await page.mouse.down()
    await page.mouse.move(box.x + 120, box.y + 90, { steps: 5 })
    await page.mouse.up()

    await expect(page.getByTestId('floor-plan-svg')).not.toHaveAttribute('viewBox', '0 0 1000 600')
    expect(await node.getAttribute('transform')).toBe(before)
  })

  test('the API answers the Administrator, and refuses every write verb', async ({ page }) => {
    await signIn(page, 'administrator')

    expect(await apiStatus(page, apiUrl())).toBe(200)

    for (const method of ['POST', 'PUT', 'PATCH', 'DELETE']) {
      expect((await apiFetch(page, apiUrl(), method, {})).status, method).toBe(405)
    }
  })
})

/**
 * WP-D — placement. Every test here moves a real row in the dev database, so
 * each one puts its unit back through the same API before it ends; the
 * fixture command also re-seeds every position on the next `scripts/e2e.sh`.
 */
type PlanPayload = {
  data: {
    layout: { version: number }
    pcs: { id: string; name: string; x: number; y: number }[]
  }
}

const positionUrl = (version: number, pcId: string) =>
  `/api/admin/floor-plan/rooms/${room().uuid}/layouts/${version}/positions/${pcId}`

async function planUnit(page: Page, name: string) {
  const { data } = await apiJson<PlanPayload>(page, apiUrl())
  const unit = data.data.pcs.find((pc) => pc.name === name)
  if (!unit) throw new Error(`Fixture unit "${name}" is not on the plan.`)
  return { unit, version: data.data.layout.version }
}

async function putBack(page: Page, name: string, x: number, y: number) {
  const { unit, version } = await planUnit(page, name)
  const result = await apiFetch(page, positionUrl(version, unit.id), 'PATCH', { x, y, snap: false })
  expect(result.status, `restoring ${name}`).toBe(200)
}

const LABEL_POINT = /at x ([\d.]+), y ([\d.]+)$/

async function labelPoint(page: Page, name: string) {
  const label = await page
    .getByRole('button', { name: new RegExp(`^${name},`) })
    .getAttribute('aria-label')
  const match = label?.match(LABEL_POINT)
  if (!match) throw new Error(`No position in the label "${label}".`)
  return { x: Number(match[1]), y: Number(match[2]) }
}

test.describe('floor plan — administrator places units (WP-D)', () => {
  test('drags a unit; the server snaps it, and it is still there after a reload', async ({
    page,
  }) => {
    await signIn(page, 'administrator')
    await goto(page, mapUrl())

    const name = manifest().pc_unit.pc_name
    const { unit: before } = await planUnit(page, name)
    const node = page.getByRole('button', { name: new RegExp(`^${name},`) })
    // `page.mouse` works in viewport coordinates: a unit below the fold would
    // be "pressed" on the page background, not on the unit.
    await node.scrollIntoViewIfNeeded()
    const box = (await node.boundingBox())!

    const saved = page.waitForResponse(
      (response) =>
        response.request().method() === 'PATCH' && response.url().includes('/positions/'),
    )
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2)
    await page.mouse.down()
    await page.mouse.move(box.x + box.width / 2 + 160, box.y + box.height / 2 - 70, { steps: 8 })
    // Mid-drag: the unit is lifted and follows the pointer.
    await expect(node).toHaveAttribute('data-moving', 'true')
    await page.mouse.up()
    const response = await saved
    expect(response.status()).toBe(200)
    const stored = ((await response.json()) as { data: { x: number; y: number } }).data

    // The server's point: on the 20 px grid, and moved.
    expect(stored.x % 20).toBe(0)
    expect(stored.y % 20).toBe(0)
    expect(stored).not.toEqual({ x: before.x, y: before.y })
    await expect(page.getByTestId('floor-plan-announcer')).toContainText(`${name} placed at`)

    await page.reload()
    await expect(page.getByRole('button', { name: new RegExp(`^${name},`) })).toBeVisible()
    expect(await labelPoint(page, name)).toEqual({ x: stored.x, y: stored.y })

    await putBack(page, name, before.x, before.y)
  })

  test('moves a unit with the keyboard alone: Enter, arrows, Enter', async ({ page }) => {
    await signIn(page, 'administrator')
    await goto(page, mapUrl())

    const name = 'E2E Plan PC 1'
    const { unit: before } = await planUnit(page, name)
    const node = page.getByRole('button', { name: new RegExp(`^${name},`) })

    await node.focus()
    await page.keyboard.press('Enter')
    await expect(page.getByTestId('floor-plan-announcer')).toContainText(`Moving ${name}`)
    await page.keyboard.press('ArrowRight')
    await page.keyboard.press('ArrowRight')
    await page.keyboard.press('ArrowDown')
    await page.keyboard.press('Enter')

    const expected = { x: before.x + 40, y: before.y + 20 }
    await expect(page.getByTestId('floor-plan-announcer')).toContainText(
      `${name} placed at x ${expected.x}, y ${expected.y}`,
    )

    await page.reload()
    await expect(page.getByRole('button', { name: new RegExp(`^${name},`) })).toBeVisible()
    expect(await labelPoint(page, name)).toEqual(expected)

    await putBack(page, name, before.x, before.y)
  })

  test('Escape abandons a keyboard move and nothing is saved', async ({ page }) => {
    await signIn(page, 'administrator')
    await goto(page, mapUrl())

    const name = 'E2E Plan PC 2'
    const { unit: before } = await planUnit(page, name)
    let writes = 0
    page.on('request', (request) => {
      if (request.method() === 'PATCH') writes += 1
    })

    await page.getByRole('button', { name: new RegExp(`^${name},`) }).focus()
    await page.keyboard.press('Enter')
    await page.keyboard.press('ArrowDown')
    await page.keyboard.press('Escape')

    await expect(page.getByTestId('floor-plan-announcer')).toContainText('Move cancelled')
    expect(await labelPoint(page, name)).toEqual({ x: before.x, y: before.y })
    expect(writes).toBe(0)
  })

  test('places a unit by exact coordinates', async ({ page }) => {
    await signIn(page, 'administrator')
    await goto(page, mapUrl())

    const name = 'E2E Plan PC 3'
    const { unit: before } = await planUnit(page, name)

    await page
      .getByLabel('Unit', { exact: true })
      .selectOption({ label: `${name} — at x ${before.x}, y ${before.y}` })
    await page.getByRole('spinbutton', { name: 'X position' }).fill('503')
    await page.getByRole('spinbutton', { name: 'Y position' }).fill('417')
    await page.getByRole('button', { name: 'Move unit' }).click()

    // 503,417 on a 20 px grid is 500,420 — the server's answer is what shows.
    await expect(page.getByTestId('floor-plan-announcer')).toContainText(
      `${name} placed at x 500, y 420, aligned to the grid`,
    )
    expect(await labelPoint(page, name)).toEqual({ x: 500, y: 420 })

    await putBack(page, name, before.x, before.y)
  })

  test('the write API refuses a point off the canvas and an unknown unit', async ({ page }) => {
    await signIn(page, 'administrator')
    await goto(page, mapUrl())

    const { unit, version } = await planUnit(page, 'E2E Plan PC 4')

    expect(
      (await apiFetch(page, positionUrl(version, unit.id), 'PATCH', { x: 5000, y: 10 })).status,
    ).toBe(422)
    expect(
      (
        await apiFetch(
          page,
          positionUrl(version, '00000000-0000-4000-8000-000000000000'),
          'PATCH',
          { x: 10, y: 10 },
        )
      ).status,
    ).toBe(404)
    expect(
      (await apiFetch(page, positionUrl(version + 50, unit.id), 'PATCH', { x: 10, y: 10 })).status,
    ).toBe(404)
  })
})

for (const role of ['technician', 'teacher'] as const) {
  test.describe(`floor plan — ${role} cannot place units`, () => {
    test('the write API refuses them with real identifiers', async ({ page }) => {
      // Arrange as an administrator to learn real identifiers, then switch.
      await signIn(page, 'administrator')
      await goto(page, mapUrl())
      const { unit, version } = await planUnit(page, 'E2E Plan PC 5')

      await signOut(page)
      await signIn(page, role)

      expect(
        (await apiFetch(page, positionUrl(version, unit.id), 'PATCH', { x: 40, y: 40 })).status,
      ).toBe(403)
      // An invented unit gets the identical answer — no oracle.
      expect(
        (
          await apiFetch(
            page,
            positionUrl(version, '00000000-0000-4000-8000-000000000000'),
            'PATCH',
            { x: 40, y: 40 },
          )
        ).status,
      ).toBe(403)
    })
  })
}

for (const role of ['technician', 'teacher'] as const) {
  test.describe(`floor plan — ${role} is refused`, () => {
    test('has no navigation entry, and the URL shows Access denied', async ({ page }) => {
      await signIn(page, role)

      await expect(page.getByRole('link', { name: /Floor plan/ })).toHaveCount(0)

      for (const path of ['/app/floor-plan', mapUrl()]) {
        await goto(page, path)
        await expect(page.getByRole('heading', { name: 'Access denied' })).toBeVisible()
        await expect(page.getByTestId('floor-plan-svg')).toHaveCount(0)
      }
    })

    test('is refused by the API itself, for a real room and an invented one', async ({ page }) => {
      await signIn(page, role)

      // The real uuid is not a credential …
      expect(await apiStatus(page, apiUrl())).toBe(403)
      // … and the answer is identical for one that does not exist.
      expect(
        await apiStatus(page, '/api/admin/floor-plan/rooms/00000000-0000-4000-8000-000000000000'),
      ).toBe(403)
    })
  })
}

test.describe('floor plan — unauthenticated', () => {
  test('is refused by the API and by the route', async ({ page }) => {
    await goto(page, '/sign-in')

    expect(await apiStatus(page, apiUrl())).toBe(401)

    await goto(page, mapUrl())
    await expect(page).toHaveURL(/\/sign-in/)
    await expect(page.getByTestId('floor-plan-svg')).toHaveCount(0)
  })
})

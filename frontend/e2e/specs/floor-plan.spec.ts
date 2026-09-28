import { expect, test } from '@playwright/test'
import { apiFetch, apiStatus, goto, signIn } from '../fixtures/app'
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

  test('dragging the map moves the view and never a machine', async ({ page }) => {
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

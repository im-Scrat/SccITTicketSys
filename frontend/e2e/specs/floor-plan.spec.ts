import { expect, test, type Locator, type Page } from '@playwright/test'
import { apiFetch, apiJson, apiStatus, goto, openNavigation, signIn } from '../fixtures/app'
import { manifest } from '../fixtures/manifest'

/**
 * The Floorplan, through a browser — Phase 2.8 (WP-C..G), completed in Phase
 * 2.11 (SDD DD-101..DD-104).
 *
 * Administrator-only, so the negative assertions are made against the real
 * stack. Edits go through edit mode and a single Save (`PUT …/layouts/{v}`);
 * every test that changes the fixture room puts its layout back afterwards,
 * through the same endpoint, so the next test starts from the seeded plan.
 *
 * The fixture room's active layout carries a unit in every PC status plus the
 * fixture PC (`sccit:e2e-fixtures`).
 */

const room = () => manifest().room
const mapUrl = () => `/app/floor-plan/rooms/${room().uuid}`
const apiUrl = () => `/api/admin/floor-plan/rooms/${room().uuid}`

interface Plan {
  layout: { version: number; revision: number; width: number; height: number; grid_size: number }
  pcs: { id: string; name: string; x: number; y: number; rotation: number }[]
  fixtures: unknown[]
}

const node = (page: Page, name: string): Locator =>
  page.locator(`[data-testid="floor-plan-node"][aria-label^="${name},"]`)

async function plan(page: Page): Promise<Plan> {
  const result = await apiJson<{ data: Plan }>(page, apiUrl())
  expect(result.status).toBe(200)
  return result.data.data
}

/** Put the room back exactly as `original` was, at the current revision. */
async function restore(page: Page, original: Plan) {
  const current = await plan(page)
  const result = await apiFetch(page, `${apiUrl()}/layouts/${current.layout.version}`, 'PUT', {
    expected_revision: current.layout.revision,
    settings: {
      width: original.layout.width,
      height: original.layout.height,
      grid_size: original.layout.grid_size,
    },
    workstations: original.pcs.map((p) => ({
      pc_unit: p.id,
      x: p.x,
      y: p.y,
      rotation: p.rotation,
    })),
    fixtures: original.fixtures,
  })
  expect(result.status).toBe(200)
}

async function labelPoint(locator: Locator) {
  const label = (await locator.getAttribute('aria-label')) ?? ''
  const match = /at x (\d+), y (\d+)/.exec(label)
  return { x: Number(match?.[1]), y: Number(match?.[2]) }
}

test.describe('Floorplan — overview', () => {
  test('lists the fixture room as a card that opens its editor', async ({ page }) => {
    await signIn(page, 'administrator')
    await goto(page, '/app/floor-plan')

    await expect(page.getByRole('heading', { name: 'Floorplan', level: 1 })).toBeVisible()
    const link = page.getByRole('link', { name: room().name, exact: true })
    await expect(link).toBeVisible()
    await link.click()
    await expect(page).toHaveURL(new RegExp(`/app/floor-plan/rooms/${room().uuid}$`))
  })
})

test.describe('Floorplan — administrator views a room', () => {
  test('draws one node per placed unit, with status in words', async ({ page }) => {
    await signIn(page, 'administrator')
    await goto(page, mapUrl())

    await expect(page.getByRole('heading', { name: room().name, level: 1 })).toBeVisible()
    await expect(page.getByTestId('floor-plan-node')).toHaveCount(4)
    for (const label of ['Available', 'Pending Repair', 'Needs Fix']) {
      await expect(
        page.getByRole('group', { name: 'Highlight by status' }).getByText(label),
      ).toBeVisible()
    }
    await expect(page.getByText(/does not monitor whether a PC is switched on/)).toBeVisible()
  })

  test('zooms with the toolbar and fits the room again', async ({ page }) => {
    await signIn(page, 'administrator')
    await goto(page, mapUrl())

    const svg = page.getByTestId('floor-plan-svg')
    const before = await svg.getAttribute('viewBox')
    await page.getByRole('button', { name: 'Zoom in' }).click()
    await expect(svg).not.toHaveAttribute('viewBox', before ?? '')
    await page.getByRole('button', { name: 'Fit the room' }).click()
    await expect(svg).toHaveAttribute('viewBox', before ?? '')
  })
})

test.describe('Floorplan — administrator edits and saves', () => {
  test('drags a unit, saves, and it is still there after a reload', async ({ page }) => {
    await signIn(page, 'administrator')
    await goto(page, mapUrl())
    const original = await plan(page)

    try {
      await page.getByRole('button', { name: 'Edit layout' }).click()
      const unit = node(page, 'E2E Plan PC 1')
      const start = await labelPoint(unit)
      const box = (await unit.boundingBox())!
      await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2)
      await page.mouse.down()
      await page.mouse.move(box.x + box.width / 2 + 60, box.y + box.height / 2 + 4, { steps: 6 })
      await page.mouse.up()

      await expect(page.getByText(/Unsaved changes \(1\)/)).toBeVisible()
      await page.getByRole('button', { name: 'Save' }).click()
      await expect(page.getByText(/^Saved/)).toBeVisible()

      const moved = await labelPoint(unit)
      expect(moved.x).not.toBe(start.x)
      // Snapped: a 40-unit workstation's centre lands on the 20-unit grid.
      expect(moved.x % 20).toBe(0)

      await page.reload()
      await expect(node(page, 'E2E Plan PC 1')).toHaveAttribute(
        'aria-label',
        new RegExp(`at x ${moved.x}, y ${moved.y}`),
      )
    } finally {
      await restore(page, original)
    }
  })

  test('moves a unit with the keyboard alone and saves with Ctrl+S', async ({ page }) => {
    await signIn(page, 'administrator')
    await goto(page, mapUrl())
    const original = await plan(page)

    try {
      await page.getByRole('button', { name: 'Edit layout' }).click()
      const unit = node(page, 'E2E Plan PC 2')
      const start = await labelPoint(unit)
      await unit.focus()
      await page.keyboard.press('Enter')
      await page.keyboard.press('ArrowDown')
      await expect(unit).toHaveAttribute(
        'aria-label',
        new RegExp(`at x ${start.x}, y ${start.y + 20}`),
      )

      await page.keyboard.press('Control+s')
      await expect(page.getByText(/^Saved/)).toBeVisible()
      expect((await plan(page)).pcs.find((p) => p.name === 'E2E Plan PC 2')?.y).toBe(start.y + 20)
    } finally {
      await restore(page, original)
    }
  })

  test('discarding leaves the saved layout untouched', async ({ page }) => {
    await signIn(page, 'administrator')
    await goto(page, mapUrl())
    const before = await plan(page)

    await page.getByRole('button', { name: 'Edit layout' }).click()
    const unit = node(page, 'E2E Plan PC 2')
    await unit.focus()
    await page.keyboard.press('Enter')
    await page.keyboard.press('ArrowRight')
    await page.getByRole('button', { name: 'Discard' }).click()

    expect((await plan(page)).layout.revision).toBe(before.layout.revision)
  })
})

test.describe('Floorplan — two administrators', () => {
  test('a save made in another browser is combined, never overwritten', async ({ browser }) => {
    const contextA = await browser.newContext()
    const contextB = await browser.newContext()
    const pageA = await contextA.newPage()
    const pageB = await contextB.newPage()

    await signIn(pageA, 'administrator')
    await signIn(pageB, 'administrator')
    await Promise.all([goto(pageA, mapUrl()), goto(pageB, mapUrl())])
    const original = await plan(pageA)

    try {
      for (const page of [pageA, pageB])
        await page.getByRole('button', { name: 'Edit layout' }).click()

      // A moves PC 1 and saves.
      await node(pageA, 'E2E Plan PC 1').focus()
      await pageA.keyboard.press('Enter')
      await pageA.keyboard.press('ArrowDown')
      await pageA.getByRole('button', { name: 'Save' }).click()
      await expect(pageA.getByText(/^Saved/)).toBeVisible()

      // B, still on the old revision, moves PC 2 and saves.
      await node(pageB, 'E2E Plan PC 2').focus()
      await pageB.keyboard.press('Enter')
      await pageB.keyboard.press('ArrowDown')
      await pageB.getByRole('button', { name: 'Save' }).click()

      const dialog = pageB.getByRole('dialog', {
        name: 'This Floorplan changed while you were editing',
      })
      await expect(dialog).toBeVisible({ timeout: 10_000 })
      await dialog.getByRole('button', { name: 'Save combined layout' }).click()
      await expect(pageB.getByText(/^Saved/)).toBeVisible()

      const after = await plan(pageB)
      const before1 = original.pcs.find((p) => p.name === 'E2E Plan PC 1')!
      const before2 = original.pcs.find((p) => p.name === 'E2E Plan PC 2')!
      expect(after.pcs.find((p) => p.name === 'E2E Plan PC 1')?.y).toBe(before1.y + 20)
      expect(after.pcs.find((p) => p.name === 'E2E Plan PC 2')?.y).toBe(before2.y + 20)
    } finally {
      await restore(pageA, original)
      await contextA.close()
      await contextB.close()
    }
  })

  test('a PC status change made in one browser appears live in another', async ({ browser }) => {
    const contextA = await browser.newContext()
    const contextB = await browser.newContext()

    try {
      const pageA = await contextA.newPage()
      const pageB = await contextB.newPage()
      await signIn(pageA, 'administrator')
      await signIn(pageB, 'administrator')
      await Promise.all([goto(pageA, mapUrl()), goto(pageB, mapUrl())])

      const unit = (await plan(pageA)).pcs.find((p) => p.name === 'E2E Plan PC 3')!
      // Seed order: PcStatus::cases()[2].
      const originalStatus = 'needs_fix'

      expect(
        (await apiFetch(pageA, `/api/admin/pc-units/${unit.id}`, 'PUT', { status: 'available' }))
          .status,
      ).toBe(200)
      await expect(node(pageB, 'E2E Plan PC 3')).toHaveAttribute(
        'aria-label',
        /^E2E Plan PC 3, Available,/,
        {
          timeout: 10_000,
        },
      )

      expect(
        (await apiFetch(pageA, `/api/admin/pc-units/${unit.id}`, 'PUT', { status: originalStatus }))
          .status,
      ).toBe(200)
      await expect(node(pageB, 'E2E Plan PC 3')).toHaveAttribute(
        'aria-label',
        /^E2E Plan PC 3, Needs Fix,/,
        {
          timeout: 10_000,
        },
      )
    } finally {
      await contextA.close()
      await contextB.close()
    }
  })
})

test.describe('Floorplan — the write path', () => {
  test('refuses a stale revision with the current plan, and the retired per-unit PATCH is gone', async ({
    page,
  }) => {
    await signIn(page, 'administrator')
    await goto(page, mapUrl())
    const current = await plan(page)

    const stale = await apiFetch(page, `${apiUrl()}/layouts/${current.layout.version}`, 'PUT', {
      expected_revision: current.layout.revision + 99,
      settings: {
        width: current.layout.width,
        height: current.layout.height,
        grid_size: current.layout.grid_size,
      },
      workstations: [],
      fixtures: [],
    })
    expect(stale.status).toBe(409)
    expect(JSON.parse(stale.body).code).toBe('layout_stale')

    const pc = current.pcs[0]
    const patch = await apiFetch(
      page,
      `${apiUrl()}/layouts/${current.layout.version}/positions/${pc.id}`,
      'PATCH',
      { x: 40, y: 40 },
    )
    expect([404, 405]).toContain(patch.status)
  })
})

for (const role of ['technician', 'teacher'] as const) {
  test.describe(`Floorplan — ${role} is refused`, () => {
    test('has no navigation entry, and the URLs show Access denied', async ({ page }) => {
      await signIn(page, role)

      const nav = await openNavigation(page)
      await expect(nav.getByRole('link', { name: 'Dashboard' })).toBeVisible()
      await expect(nav.getByRole('link', { name: /Floorplan/ })).toHaveCount(0)

      for (const path of ['/app/floor-plan', mapUrl()]) {
        await goto(page, path)
        await expect(page.getByRole('heading', { name: 'Access denied' })).toBeVisible()
        await expect(page.getByTestId('floor-plan-svg')).toHaveCount(0)
      }
    })

    test('is refused by the API, identically for a real room and an invented one', async ({
      page,
    }) => {
      await signIn(page, role)
      const invented = '/api/admin/floor-plan/rooms/00000000-0000-4000-8000-000000000000'

      expect(await apiStatus(page, apiUrl())).toBe(403)
      expect(await apiStatus(page, invented)).toBe(403)
      expect(await apiStatus(page, '/api/admin/floor-plan/rooms')).toBe(403)
      expect((await apiFetch(page, `${apiUrl()}/layouts/1`, 'PUT', {})).status).toBe(403)
      expect((await apiFetch(page, `${invented}/layouts/1`, 'PUT', {})).status).toBe(403)
    })
  })
}

test.describe('Floorplan — unauthenticated', () => {
  test('is refused by the API and by the route', async ({ page }) => {
    await goto(page, '/sign-in')

    expect(await apiStatus(page, apiUrl())).toBe(401)

    await goto(page, mapUrl())
    await expect(page).toHaveURL(/\/sign-in/)
    await expect(page.getByTestId('floor-plan-svg')).toHaveCount(0)
  })
})

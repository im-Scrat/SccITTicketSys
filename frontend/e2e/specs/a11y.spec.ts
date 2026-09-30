import AxeBuilder from '@axe-core/playwright'
import { expect, test, type Page } from '@playwright/test'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { manifest, type Role } from '../fixtures/manifest'
import { goto, signIn } from '../fixtures/app'

/**
 * Accessibility regression — WP-2.7d.
 *
 * SPMP WP-2.6b recorded "axe/WCAG regression automation" as deferred. This is
 * that automation. It is deliberately a **regression** gate, not a conformance
 * audit: the point is that the accessibility of these screens can only improve
 * from here, not that they are already WCAG AA (the audit itself is WP-2.11).
 *
 * ── How the baseline works ────────────────────────────────────────────────
 * `a11y-baseline.json` records the rule violations that already existed when
 * this harness was introduced, per route. A run fails when a route produces a
 * serious or critical violation that is NOT in its baseline — i.e. when a
 * change makes accessibility worse. Fixing a baselined issue never fails the
 * build; it just leaves a stale entry, which the run reports so the baseline
 * can be tightened.
 *
 * Starting from an empty baseline and "fixing everything first" was the
 * alternative. It would have meant either a permanently red gate or a silent
 * one, and a gate nobody can keep green is a gate nobody reads.
 *
 * ── Why only serious and critical fail ────────────────────────────────────
 * axe's `minor` and `moderate` findings are dominated by advisory rules
 * (landmark preferences, heading-order suggestions) that are judgement calls.
 * Gating on them trains people to add exceptions. Serious and critical are the
 * ones that stop somebody using the page.
 */

type Baseline = { routes: Record<string, string[]> }

const BASELINE: Baseline = JSON.parse(
  readFileSync(fileURLToPath(new URL('../a11y-baseline.json', import.meta.url)), 'utf8'),
) as Baseline

const BLOCKING_IMPACTS = new Set(['serious', 'critical'])

/** WCAG 2.0/2.1 levels A and AA — the standard the SRS names (NFR-ACC). */
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']

/**
 * `key` identifies the baseline entry and is NOT always the route: `/app` is
 * the dashboard for all three roles and renders different widgets for each, so
 * one shared entry would let a regression on the technician dashboard hide
 * behind an administrator finding.
 */
async function auditRoute(
  page: Page,
  route: string,
  key: string = route,
  /**
   * Runs after the route settles and before axe does. A dropdown's semantics
   * are not exercised by auditing the page behind it, so the panel audits open
   * the panel here rather than auditing a closed bell and calling it covered.
   */
  prepare?: (page: Page) => Promise<void>,
): Promise<void> {
  await goto(page, route)
  if (prepare) await prepare(page)

  const results = await new AxeBuilder({ page }).withTags(TAGS).analyze()

  const blocking = results.violations.filter((v) => BLOCKING_IMPACTS.has(v.impact ?? ''))
  const allowed = new Set(BASELINE.routes[key] ?? [])
  const regressions = blocking.filter((v) => !allowed.has(v.id))

  // A stale baseline entry means somebody fixed something — say so, so the
  // entry can be removed, but never fail the run for an improvement.
  const stillFailing = new Set(blocking.map((v) => v.id))
  for (const id of allowed) {
    if (!stillFailing.has(id)) {
      // eslint-disable-next-line no-console
      console.log(`  a11y: "${id}" no longer fires on ${key} — remove it from the baseline.`)
    }
  }

  expect(
    regressions.map((v) => `${v.id} (${v.impact}, ${v.nodes.length} node(s)): ${v.help}`),
    `New serious/critical accessibility violations on ${key}`,
  ).toEqual([])
}

test.describe('accessibility — public surfaces', () => {
  for (const route of ['/', '/sign-in', '/register', '/forgot-password']) {
    test(`axe: ${route}`, async ({ page }) => {
      await auditRoute(page, route)
    })
  }
})

/**
 * Authenticated surfaces, audited as the role that actually owns them. Auditing
 * an administrator screen as a teacher would only ever measure the 403 page.
 */
const AUTHENTICATED: Record<Role, string[]> = {
  administrator: [
    '/app',
    '/app/users',
    '/app/locations',
    '/app/assets',
    '/app/tickets/manage',
    '/app/maintenance/manage',
    '/app/work-support/manage',
    // WP-M: the predictive-maintenance review list. Its detail page is audited
    // separately below because its address depends on a fixture uuid.
    '/app/predictions',
    // WP-2.7b. Audited for all three roles rather than once, because the centre
    // renders whatever that role was actually sent — an administrator's SLA
    // warnings and a teacher's ticket updates are different rows in different
    // tones, and one shared audit would let a regression on one hide behind a
    // pass on another.
    '/app/notifications',
    // WP-2.7c: the reader, plus the administrator-only management surface.
    '/app/announcements',
    '/app/announcements/manage',
    // WP-C: the floor-plan room picker. The map itself needs the fixture room's
    // uuid, so it is audited by its own test below.
    '/app/floor-plan',
  ],
  technician: [
    '/app',
    '/app/tickets/assigned',
    '/app/maintenance',
    '/app/work-support',
    '/app/notifications',
    '/app/announcements',
  ],
  teacher: [
    '/app',
    '/app/tickets',
    '/app/tickets/mine',
    '/app/tickets/new',
    // Also the WP-2.7b preference matrix, which lives on this page.
    '/app/account',
    '/app/notifications',
    '/app/announcements',
  ],
}

for (const [role, routes] of Object.entries(AUTHENTICATED) as [Role, string[]][]) {
  test.describe(`accessibility — ${role} surfaces`, () => {
    for (const route of routes) {
      test(`axe: ${role} ${route}`, async ({ page }) => {
        await signIn(page, role)
        await auditRoute(page, route, `${role} ${route}`)
      })
    }
  })
}

/**
 * The notification panel, audited **open** — WP-2.7b.
 *
 * A dropdown that is closed contributes nothing to the accessibility tree, so
 * auditing the page behind it proves only that the bell has a name. The panel
 * is where the list semantics, the dialog's own name and the row controls live,
 * and it renders on every authenticated screen in the product — which makes it
 * the single highest-traffic piece of markup this work package added.
 */
test.describe('accessibility — the notification panel', () => {
  test('axe: administrator notification panel (open)', async ({ page }) => {
    await signIn(page, 'administrator')
    await auditRoute(page, '/app', 'administrator notification panel', async (target) => {
      await target.getByRole('button', { name: /^Notifications/ }).click()
      await expect(target.getByRole('dialog', { name: 'Notifications' })).toBeVisible()
    })
  })
})

/**
 * The floor-plan map, audited **with units on it** — WP-C.
 *
 * An empty grid would prove only that the toolbar has names. The fixture room
 * carries an active layout with a unit in every PC status, so the audit sees
 * all six shapes, their written labels, the legend and the text alternative —
 * the parts where colour-only signalling or an unnamed graphic would show up.
 */
test.describe('accessibility — the floor-plan map', () => {
  test('axe: administrator floor-plan map', async ({ page }) => {
    await signIn(page, 'administrator')
    await auditRoute(
      page,
      `/app/floor-plan/rooms/${manifest().room.uuid}`,
      'administrator floor-plan map',
      async (target) => {
        await expect(target.getByTestId('floor-plan-node')).toHaveCount(7)
      },
    )
  })

  /**
   * WP-D — the editor mid-move: units are buttons, one is lifted into keyboard
   * move mode (selection ring, drop shadow, live-region announcement), and the
   * numeric placement form is on the page. The states a static audit of the
   * map would never reach.
   */
  test('axe: administrator floor-plan map with a unit in move mode', async ({ page }) => {
    await signIn(page, 'administrator')
    await auditRoute(
      page,
      `/app/floor-plan/rooms/${manifest().room.uuid}`,
      'administrator floor-plan map (moving)',
      async (target) => {
        const unit = target.getByRole('button', { name: /^E2E Plan PC 1,/ })
        await unit.focus()
        await target.keyboard.press('Enter')
        await target.keyboard.press('ArrowRight')
        await expect(unit).toHaveAttribute('data-moving', 'true')
        await expect(
          target.getByRole('heading', { name: 'Place a unit by position' }),
        ).toBeVisible()
      },
    )
    // Leave the unit where it was: Escape abandons the move, nothing is sent.
    await page.keyboard.press('Escape')
  })
})

/**
 * The predictive-maintenance finding page — WP-M.
 *
 * Audited on its own because the address carries a fixture uuid, and because it
 * is the densest page this work package touched: fact grids, evidence, a repair
 * history and a verdict dialog. Both fixture findings are audited so the one
 * with a time window and the one without are each covered.
 */
test.describe('accessibility — predictive-maintenance finding', () => {
  for (const key of ['with_window', 'without_window'] as const) {
    test(`axe: administrator prediction (${key})`, async ({ page }) => {
      await signIn(page, 'administrator')
      await auditRoute(
        page,
        `/app/predictions/${manifest().predictions[key].uuid}`,
        `administrator prediction (${key})`,
      )
    })
  }
})

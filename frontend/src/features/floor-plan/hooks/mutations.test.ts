import { describe, expect, it } from 'vitest'
import { pc, roomPlan, unplacedPc } from '../test/fixtures'
import { withStatusChanged } from './mutations'

/**
 * `withStatusChanged` — the WP-E broadcast reconciliation merge, exercised as
 * a pure function so its three cases (placed, unplaced, absent) do not depend
 * on Echo, TanStack Query or a rendered component.
 */
describe('withStatusChanged', () => {
  it("updates a placed unit's status, leaving its position untouched", () => {
    const plan = roomPlan({ pcs: [pc(1, 'online', { x: 250, y: 90 })] })

    const next = withStatusChanged(plan, {
      id: 'pc-uuid-1',
      name: 'PC-01',
      unit_code: 'LAB-1',
      status: { value: 'offline', label: 'Offline', tone: 'danger' },
    })

    expect(next.pcs[0]).toMatchObject({ x: 250, y: 90, status: { value: 'offline' } })
    expect(next).not.toBe(plan)
  })

  it("updates an unplaced unit's status", () => {
    const plan = roomPlan({ unplaced: [unplacedPc(9, 'available')], unplaced_count: 1 })

    const next = withStatusChanged(plan, {
      id: 'unplaced-uuid-9',
      name: 'PC-U9',
      unit_code: 'LAB-U9',
      status: { value: 'retired', label: 'Retired', tone: 'neutral' },
    })

    expect(next.unplaced[0]?.status).toEqual({
      value: 'retired',
      label: 'Retired',
      tone: 'neutral',
    })
  })

  it('returns the same plan reference when the unit is neither placed nor unplaced here', () => {
    const plan = roomPlan()

    const next = withStatusChanged(plan, {
      id: 'stranger-uuid',
      name: 'Stranger',
      unit_code: 'X-1',
      status: { value: 'offline', label: 'Offline', tone: 'danger' },
    })

    expect(next).toBe(plan)
  })

  it('also updates the name and unit code, matching what the server sent', () => {
    const plan = roomPlan({ pcs: [pc(1, 'online')] })

    const next = withStatusChanged(plan, {
      id: 'pc-uuid-1',
      name: 'Renamed',
      unit_code: 'NEW-CODE',
      status: { value: 'online', label: 'Online', tone: 'success' },
    })

    expect(next.pcs[0]).toMatchObject({ name: 'Renamed', unit_code: 'NEW-CODE' })
  })
})

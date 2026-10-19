import { describe, expect, it } from 'vitest'
import {
  PC_STATUS,
  PC_STATUS_VALUES,
  isPcStatus,
  pcStatusCategory,
  pcStatusLabel,
} from './pcStatus'

/**
 * Phase 2.11 (SDD DD-100). The server sends `label` and `tone` with every PC
 * status (`PcStatus::label()/tone()`, pinned by the backend's
 * FloorPlanEnumsTest). These are the same literal values, so the two tables
 * cannot drift without one of the two tests failing.
 */
describe('the shared PC status table', () => {
  it('has exactly the three stored statuses, in order', () => {
    expect(PC_STATUS_VALUES).toEqual(['available', 'pending_repair', 'needs_fix'])
  })

  it.each([
    ['available', 'Available', 'success'],
    ['pending_repair', 'Pending Repair', 'warning'],
    ['needs_fix', 'Needs Fix', 'danger'],
    ['unassigned', 'Unassigned', 'neutral'],
  ] as const)('shows %s as %s in the %s tone', (value, label, tone) => {
    expect(PC_STATUS[value].label).toBe(label)
    expect(PC_STATUS[value].tone).toBe(tone)
  })

  it('gives every category its own tone and its own icon — colour is never the only signal', () => {
    const categories = Object.values(PC_STATUS)
    expect(new Set(categories.map((c) => c.tone)).size).toBe(categories.length)
    expect(new Set(categories.map((c) => c.icon)).size).toBe(categories.length)
  })

  it('never offers online or offline as a status', () => {
    expect(isPcStatus('online')).toBe(false)
    expect(isPcStatus('offline')).toBe(false)
  })

  it('labels values found in history, including the pre-2.11 ones', () => {
    expect(pcStatusLabel('needs_fix')).toBe('Needs Fix')
    expect(pcStatusLabel('under_maintenance')).toBe('Under Maintenance')
    expect(pcStatusLabel('online')).toBe('Online')
    expect(pcStatusLabel('something_new')).toBe('something_new')
    expect(pcStatusLabel(null)).toBe('—')
  })

  it('shows a PC with no room as Unassigned, whatever its repair status', () => {
    expect(pcStatusCategory('needs_fix', false)).toBe('unassigned')
    expect(pcStatusCategory('needs_fix', true)).toBe('needs_fix')
  })
})

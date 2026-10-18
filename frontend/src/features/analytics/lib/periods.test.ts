import { describe, expect, it } from 'vitest'
import { canStepForward, periodStart, stepLabel, stepPeriod } from './periods'

describe('report period stepping', () => {
  it('starts weeks on Monday, whatever day the anchor is', () => {
    expect(periodStart('week', '2026-10-18')).toBe('2026-10-12') // a Sunday
    expect(periodStart('week', '2026-10-12')).toBe('2026-10-12')
  })

  it('steps whole calendar periods, landing on their first day', () => {
    expect(stepPeriod('month', '2026-10-18', -1)).toBe('2026-09-01')
    expect(stepPeriod('month', '2026-03-31', -1)).toBe('2026-02-01')
    expect(stepPeriod('year', '2026-10-18', -1)).toBe('2025-01-01')
    expect(stepPeriod('week', '2026-10-18', -1)).toBe('2026-10-05')
    expect(stepPeriod('day', '2026-03-01', -1)).toBe('2026-02-28')
  })

  it('never steps into a period that has not begun', () => {
    expect(canStepForward('month', '2026-10-01', '2026-10-18')).toBe(false)
    expect(canStepForward('month', '2026-09-01', '2026-10-18')).toBe(true)
    expect(canStepForward('day', '2026-10-17', '2026-10-18')).toBe(true)
  })

  it('names the stepper buttons for what they do', () => {
    expect(stepLabel('month', -1)).toBe('Previous month')
    expect(stepLabel('week', 1)).toBe('Next week')
  })
})

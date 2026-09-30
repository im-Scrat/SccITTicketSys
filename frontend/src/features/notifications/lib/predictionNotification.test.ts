import { LineChart, Wrench } from 'lucide-react'
import { describe, expect, it } from 'vitest'
import { notificationIcon, safeActionPath } from './presentation'

/**
 * WP-M's notification, as the centre presents it.
 *
 * It arrives as `type: 'maintenance'` — the baselined vocabulary was not widened
 * — with a `maintenance.prediction_generated` topic, and the topic is what picks
 * the glyph, falling back to the type's own icon for a topic this build has
 * never heard of.
 */
describe('the predictive-maintenance notification', () => {
  it('has its own glyph, distinct from the overdue-visit one it shares a type with', () => {
    expect(notificationIcon('maintenance.prediction_generated', 'maintenance')).toBe(LineChart)
    expect(notificationIcon('maintenance.due', 'maintenance')).toBe(Wrench)
  })

  it('falls back to the maintenance type icon for a topic it does not know', () => {
    expect(notificationIcon('maintenance.something_new', 'maintenance')).toBe(Wrench)
  })

  it('may be followed to the finding the server built the link to', () => {
    expect(safeActionPath('/app/predictions/8f6c1b3e-0000-4000-8000-000000000000')).toBe(
      '/app/predictions/8f6c1b3e-0000-4000-8000-000000000000',
    )
  })

  it('still refuses a destination outside the application', () => {
    expect(safeActionPath('https://elsewhere.example/app/predictions')).toBeNull()
    expect(safeActionPath('//elsewhere.example')).toBeNull()
  })
})

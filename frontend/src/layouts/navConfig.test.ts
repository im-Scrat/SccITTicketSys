import { describe, expect, it } from 'vitest'
import { navItemsFor } from './navConfig'

/**
 * Phase 2.9 — one navigation per role. The role decides which items exist;
 * the user's effective permissions can still remove one (defence in depth),
 * but a per-user grant can never add another role's surface.
 */
const all = () => true
const labels = (role: string, has: (permission: string) => boolean = all) =>
  navItemsFor(role, has).map((item) => item.label)

describe('teacher navigation', () => {
  it('is the feed-first set and nothing else', () => {
    expect(labels('teacher')).toEqual([
      'Dashboard',
      'Report a problem',
      'My tickets',
      'Announcements',
    ])
  })

  it('never offers My assets, the management surfaces or AI settings — even with every permission', () => {
    const items = labels('teacher')
    for (const label of ['My assets', 'Ticket management', 'Technicians', 'AI settings', 'Users']) {
      expect(items).not.toContain(label)
    }
  })

  it('drops "Report a problem" when tickets.create is withdrawn', () => {
    expect(labels('teacher', (p) => p !== 'tickets.create')).not.toContain('Report a problem')
  })
})

describe('technician navigation', () => {
  it('is work-first and has no community feed', () => {
    const items = labels('technician')
    expect(items).toEqual([
      'Dashboard',
      'My work',
      'Completed work',
      'Maintenance',
      'My submissions',
      'My assets',
      'Announcements',
    ])
    expect(items).not.toContain('Tickets')
  })

  it('never offers administrator surfaces, even with a per-user grant', () => {
    const items = labels('technician')
    for (const label of ['Technicians', 'AI settings', 'Floor plan', 'Support requests', 'Users']) {
      expect(items).not.toContain(label)
    }
  })
})

describe('administrator navigation', () => {
  it('adds Technicians and AI settings to the oversight set', () => {
    const items = labels('administrator')
    expect(items).toContain('Technicians')
    expect(items).toContain('AI settings')
    expect(items).toContain('Ticket management')
    expect(items).not.toContain('My submissions')
  })

  it('hides Technicians when users.view is denied to this administrator', () => {
    expect(labels('administrator', (p) => p !== 'users.view')).not.toContain('Technicians')
  })
})

it('gives an unknown role the dashboard only', () => {
  expect(labels('visitor')).toEqual(['Dashboard'])
})

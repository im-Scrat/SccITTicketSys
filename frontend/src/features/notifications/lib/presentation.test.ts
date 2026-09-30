import { Bell } from 'lucide-react'
import { describe, expect, it } from 'vitest'
import {
  notificationIcon,
  notificationTone,
  notificationTypeLabel,
  safeActionPath,
} from './presentation'

/**
 * The presentation rules, and the one security decision among them.
 *
 * `safeActionPath` is the client-side half of the FR-QR-011 discipline applied
 * to notifications: `action_url` is server-built today, but it is *stored data*,
 * and treating stored data as a trusted destination is how open redirects
 * happen. It gets its own tests because it is the only function in this slice
 * whose failure would be a vulnerability rather than a visual defect.
 *
 * The rest of the file exists to prove the maps are **open**, not exhaustive: a
 * notification type this build has never heard of must render, because the day
 * D5's announcements or WP-2.4b's procurement topics ship, the row a user
 * cannot see is the row that mattered.
 */

describe('the destination gate', () => {
  it('accepts an internal application path', () => {
    expect(safeActionPath('/app/tickets/abc')).toBe('/app/tickets/abc')
    expect(safeActionPath('/app/maintenance/1')).toBe('/app/maintenance/1')
    expect(safeActionPath('/app/work-support/manage')).toBe('/app/work-support/manage')
  })

  it('refuses an absolute URL, whatever it claims to be', () => {
    expect(safeActionPath('https://elsewhere.example/app/tickets/1')).toBeNull()
    expect(safeActionPath('http://localhost:8080/app/tickets/1')).toBeNull()
  })

  it('refuses a protocol-relative URL', () => {
    // `//host/path` inherits the current scheme and leaves the origin — the
    // classic open-redirect payload that a naive "starts with /" check passes.
    expect(safeActionPath('//elsewhere.example/app/tickets/1')).toBeNull()
  })

  it('refuses a script scheme', () => {
    expect(safeActionPath('javascript:alert(1)')).toBeNull()
    expect(safeActionPath('data:text/html,<script>alert(1)</script>')).toBeNull()
  })

  it('refuses a path outside the authenticated application', () => {
    expect(safeActionPath('/sign-in')).toBeNull()
    expect(safeActionPath('/')).toBeNull()
    expect(safeActionPath('/api/notifications')).toBeNull()
  })

  it('refuses a bare backslash host, which some parsers treat as a slash', () => {
    expect(safeActionPath('\\\\elsewhere.example/app')).toBeNull()
  })

  it('treats an absent destination as absent, not as an error', () => {
    // `account.locked` genuinely has none.
    expect(safeActionPath(null)).toBeNull()
    expect(safeActionPath(undefined)).toBeNull()
    expect(safeActionPath('')).toBeNull()
  })

  it('is not fooled by a prefix that merely looks internal', () => {
    expect(safeActionPath('/application/tickets')).toBeNull()
    expect(safeActionPath('/app')).toBeNull()
  })
})

describe('tones', () => {
  it('gives the operationally weighty types their established colours', () => {
    expect(notificationTone('warning')).toBe('warning')
    expect(notificationTone('error')).toBe('warning')
    expect(notificationTone('assignment')).toBe('primary')
  })

  it('falls back to neutral for a type it has never seen', () => {
    expect(notificationTone('procurement')).toBe('neutral')
    expect(notificationTone('')).toBe('neutral')
  })
})

describe('icons', () => {
  it('prefers the trigger over the type', () => {
    expect(notificationIcon('ticket.commented', 'ticket_update')).not.toBe(Bell)
    expect(notificationIcon('ticket.commented', 'ticket_update')).not.toBe(
      notificationIcon('ticket.assigned', 'ticket_update'),
    )
  })

  it('falls back to the type when the topic is unknown or absent', () => {
    // An announcement will arrive with a topic this build has never seen.
    expect(notificationIcon('announcement.published', 'announcement')).toBe(
      notificationIcon(null, 'announcement'),
    )
  })

  it('always returns something, even for a wholly unknown notification', () => {
    // The map must have a default arm; an exhaustive switch would throw here.
    expect(notificationIcon('procurement.approved', 'procurement')).toBe(Bell)
  })
})

describe('type labels', () => {
  it('prefers the label the server sent', () => {
    expect(
      notificationTypeLabel('ticket_update', [{ value: 'ticket_update', label: 'Ticket Update' }]),
    ).toBe('Ticket Update')
  })

  it('derives a readable label when the server has not answered yet', () => {
    expect(notificationTypeLabel('ticket_update')).toBe('Ticket Update')
    expect(notificationTypeLabel('system')).toBe('System')
  })
})

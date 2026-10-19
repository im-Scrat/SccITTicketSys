import { describe, expect, it } from 'vitest'
import {
  ASSIGNED_TICKET_VIEWS,
  ASSIGNED_VIEW_ORDER,
  parseAssignedTicketView,
} from './assignedTicketViews'

/*
 * `TechnicianDashboardParityTest` (backend) runs these exact parameter sets against
 * the dashboard figures. This pins the strings on this side, so editing a view
 * without that test noticing is a failure here first.
 */
describe('assigned ticket views', () => {
  it('sends exactly the parameters the parity tests verify', () => {
    expect(ASSIGNED_TICKET_VIEWS.open.params).toEqual({
      scope: 'assigned',
      status: 'open,assigned,in-progress,on-hold,resolved',
    })
    expect(ASSIGNED_TICKET_VIEWS['due-soon'].params).toEqual({ scope: 'assigned', due_soon: 1 })
    expect(ASSIGNED_TICKET_VIEWS.overdue.params).toEqual({ scope: 'assigned', breached: 1 })
  })

  it('sends flags as 1, never true — the API rejects the string "true"', () => {
    for (const key of ASSIGNED_VIEW_ORDER) {
      for (const value of Object.values(ASSIGNED_TICKET_VIEWS[key].params)) {
        expect(value).not.toBe(true)
      }
    }
  })

  it('gives every view a summary and an empty state', () => {
    for (const key of ASSIGNED_VIEW_ORDER) {
      const view = ASSIGNED_TICKET_VIEWS[key]
      expect(view.label).toBeTruthy()
      expect(view.summary).toBeTruthy()
      expect(view.emptyTitle).toBeTruthy()
      expect(view.emptyDescription).toBeTruthy()
    }
  })

  it('parses a known view and treats anything else as the active queue', () => {
    expect(parseAssignedTicketView('overdue')).toBe('overdue')
    expect(parseAssignedTicketView('due-soon')).toBe('due-soon')
    expect(parseAssignedTicketView('nonsense')).toBeNull()
    expect(parseAssignedTicketView(null)).toBeNull()
  })
})

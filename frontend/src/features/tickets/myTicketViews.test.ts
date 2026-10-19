import { describe, expect, it } from 'vitest'
import {
  MY_TICKET_VIEWS,
  MY_TICKET_VIEW_ORDER,
  myTicketsViewPath,
  parseMyTicketView,
} from './myTicketViews'

describe('parseMyTicketView', () => {
  it.each(MY_TICKET_VIEW_ORDER)('recognises the %s view', (view) => {
    expect(parseMyTicketView(view)).toBe(view)
  })

  it.each([null, '', 'bogus', 'OPEN', 'constructor', '__proto__', 'toString'])(
    'treats %j as no view',
    (value) => {
      expect(parseMyTicketView(value)).toBeNull()
    },
  )
})

describe('myTicketsViewPath', () => {
  it('builds the link the dashboard figures use, and the page reads back', () => {
    for (const view of MY_TICKET_VIEW_ORDER) {
      const url = new URL(myTicketsViewPath(view), 'http://localhost')

      expect(url.pathname).toBe('/app/tickets/mine')
      expect(parseMyTicketView(url.searchParams.get('view'))).toBe(view)
    }
  })
})

describe('MY_TICKET_VIEWS', () => {
  it('has a definition for every ordered view, and no stray ones', () => {
    expect(Object.keys(MY_TICKET_VIEWS).sort()).toEqual([...MY_TICKET_VIEW_ORDER].sort())
  })

  it('sends only the status filter, plus a window for the resolved view alone', () => {
    // These strings are asserted against the dashboard figures server-side
    // (TicketMineResolvedWithinTest). Changing one here means changing it there.
    expect(MY_TICKET_VIEWS.open.params).toEqual({
      status: 'open,assigned,in-progress,on-hold,resolved',
    })
    expect(MY_TICKET_VIEWS.awaiting.params).toEqual({ status: 'resolved' })
    expect(MY_TICKET_VIEWS.resolved.params).toEqual({
      status: 'resolved,closed',
      resolved_within: 30,
    })
  })
})

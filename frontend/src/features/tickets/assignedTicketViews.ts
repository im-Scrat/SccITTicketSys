import type { TicketParams } from './types'

/**
 * The saved views of the technician's My work page — and the targets of the
 * dashboard's "My work" figures (`/app/tickets/assigned?view=…`).
 *
 * Same idea as `myTicketViews.ts`: a view is a **preset of API filters**, nothing
 * more, and it lives in one place because two surfaces must agree on it — the
 * dashboard builds the link, the page reads it back — and because each view
 * mirrors a figure the server counts in `TicketMetrics`:
 *
 *   open       every open-status ticket I own, resolved work awaiting the
 *              reporter's confirmation included  (`openAssignedTo`)
 *   due-soon   unresolved, fix-by time inside the next four hours
 *              (`slaPosture().at_risk`)
 *   overdue    missed a response or fix-by deadline, FR-TKT-017
 *              (`slaPosture().breached`)
 *
 * All three send `scope=assigned` — "every ticket I own" — because the default
 * queue is *live assignments*, a different set: a resolved ticket whose
 * assignment is already completed is open work for the figure but not in the
 * queue. `TechnicianDashboardParityTest` runs these exact query strings against
 * the figures, so drift on either side fails a test instead of putting
 * "Overdue: 2" above a list of three.
 */
export const ASSIGNED_VIEW_ORDER = ['open', 'due-soon', 'overdue'] as const

export type AssignedTicketView = (typeof ASSIGNED_VIEW_ORDER)[number]

interface AssignedTicketViewDefinition {
  /** The toggle's text. Kept in step with the dashboard figure it stands for. */
  label: string
  /** The page's sub-line while the view is active. */
  summary: string
  emptyTitle: string
  emptyDescription: string
  /** Sent to `GET /tickets/assigned` verbatim. */
  params: Pick<TicketParams, 'scope' | 'status' | 'due_soon' | 'breached'>
}

export const ASSIGNED_TICKET_VIEWS: Record<AssignedTicketView, AssignedTicketViewDefinition> = {
  open: {
    label: 'Open tickets',
    summary:
      'Every open ticket that is yours, including fixes waiting for the reporter to confirm. Those are read-only for you.',
    emptyTitle: 'You have no open tickets',
    emptyDescription: 'Tickets that are assigned to you and not yet closed appear here.',
    params: { scope: 'assigned', status: 'open,assigned,in-progress,on-hold,resolved' },
  },
  'due-soon': {
    label: 'Due within 4 h',
    summary: 'Unresolved tickets that must be fixed within the next four hours, soonest first.',
    emptyTitle: 'Nothing is due in the next 4 hours',
    emptyDescription:
      'Tickets whose fix-by time is close appear here so you can get to them first.',
    params: { scope: 'assigned', due_soon: 1 },
  },
  overdue: {
    label: 'Overdue',
    summary:
      'Tickets that missed a response or fix-by deadline, most urgent first. Start with the top one.',
    emptyTitle: 'Nothing is overdue',
    emptyDescription: 'You have not missed a response or fix-by deadline on any of your tickets.',
    params: { scope: 'assigned', breached: 1 },
  },
}

/** The view a `?view=` value names, or null — an unknown value is the active queue. */
export function parseAssignedTicketView(value: string | null): AssignedTicketView | null {
  return ASSIGNED_VIEW_ORDER.find((view) => view === value) ?? null
}

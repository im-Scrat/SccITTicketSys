import type { TicketParams } from './types'

/**
 * The saved views of the My tickets page — and the targets of the teacher
 * dashboard's "My requests" figures (`/app/tickets/mine?view=…`).
 *
 * A view is a **preset of API filters**, nothing more: choosing one sends exactly
 * the `params` below and the server does the narrowing. They live in one place
 * because two surfaces must agree on them — the dashboard builds the link, the
 * page reads it back — and because each one mirrors a figure the server counts
 * in `TicketMetrics::reporterSummary()`:
 *
 *   open      the `is_open` statuses; `resolved` is one of them, so this includes
 *             what is waiting for the reporter's confirmation
 *   awaiting  `resolved` — the reporter is asked to confirm it (FR-TKT-016)
 *   resolved  resolved or closed, with `resolved_at` inside the last 30 days
 *
 * `TicketMineResolvedWithinTest` runs these exact strings against the figures,
 * so drift on either side fails a test instead of putting "Awaiting your
 * confirmation: 2" above a list of three.
 */
export const MY_TICKET_VIEW_ORDER = ['open', 'awaiting', 'resolved'] as const

export type MyTicketView = (typeof MY_TICKET_VIEW_ORDER)[number]

interface MyTicketViewDefinition {
  /** The toggle's text. Kept in step with the dashboard figure it stands for. */
  label: string
  /** The page's sub-line while the view is active. */
  summary: string
  emptyTitle: string
  emptyDescription: string
  /** Sent to `GET /tickets/mine` verbatim. */
  params: Pick<TicketParams, 'status' | 'resolved_within'>
}

export const MY_TICKET_VIEWS: Record<MyTicketView, MyTicketViewDefinition> = {
  open: {
    label: 'Open',
    summary: 'Requests that are not closed yet, newest first.',
    emptyTitle: 'Nothing open right now',
    emptyDescription: 'Requests that are still being worked on appear here.',
    params: { status: 'open,assigned,in-progress,on-hold,resolved' },
  },
  awaiting: {
    label: 'Awaiting your confirmation',
    summary: 'Marked fixed and waiting for you. Open one to check it and confirm, or reopen it.',
    emptyTitle: 'Nothing waiting for your confirmation',
    emptyDescription:
      'When a request is marked fixed, it appears here so you can check it and confirm.',
    params: { status: 'resolved' },
  },
  resolved: {
    label: 'Resolved (30 days)',
    summary: 'Resolved in the last 30 days, newest first.',
    emptyTitle: 'Nothing resolved in the last 30 days',
    emptyDescription: 'Requests marked fixed in the last 30 days appear here.',
    params: { status: 'resolved,closed', resolved_within: 30 },
  },
}

/** The view a `?view=` value names, or null — an unknown value is the unfiltered list. */
export function parseMyTicketView(value: string | null): MyTicketView | null {
  return MY_TICKET_VIEW_ORDER.find((view) => view === value) ?? null
}

export function myTicketsViewPath(view: MyTicketView): string {
  return `/app/tickets/mine?view=${view}`
}

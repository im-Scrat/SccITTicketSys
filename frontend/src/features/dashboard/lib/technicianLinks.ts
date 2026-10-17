import type { DashboardWidget, KpiItem, ListRow } from '../types'

/**
 * Where each figure and row on the technician's dashboard opens (SDD DD-81).
 *
 * The server sends the figures; this decides what they link to. They live
 * together, in one file, because each destination is only correct if the list it
 * opens is **the same set** the figure counts — and the pairs are proven equal by
 * `TechnicianDashboardParityTest` / `TechnicianMaintenanceParityTest`, which run
 * these exact query strings against the figures. Change a path here and one of
 * them fails, rather than "Overdue: 2" quietly opening a list of three.
 *
 * Kept out of the backend deliberately: a path is a client route, and the
 * assistant, which also reads these widgets, has no use for it. A `href` the
 * server does send always wins (see {@link decorateTechnicianWidget}).
 */

/** Figure → destination, by widget key then item key. */
export const TECHNICIAN_CARD_LINKS: Record<string, Record<string, string>> = {
  'my-work': {
    active_assignments: '/app/tickets/assigned',
    open_tickets: '/app/tickets/assigned?view=open',
    at_risk: '/app/tickets/assigned?view=due-soon',
    breached: '/app/tickets/assigned?view=overdue',
  },
  'my-assets': {
    assigned: '/app/my-assets',
  },
  'my-maintenance': {
    overdue: '/app/maintenance?overdue=1',
    due_soon: '/app/maintenance?due_within=7',
    in_progress: '/app/maintenance?status=in_progress',
  },
}

/** Row → destination, by widget key; the row's `id` is the record's uuid. */
export const TECHNICIAN_ROW_LINKS: Partial<Record<string, (id: string) => string>> = {
  'my-assignments': (id) => `/app/tickets/assigned/${id}`,
  'maintenance-schedule': (id) => `/app/maintenance/${id}`,
}

/** The "View all" link of a list panel — the full list the panel is a preview of. */
export const TECHNICIAN_PANEL_LINKS: Partial<Record<string, { href: string; label: string }>> = {
  'my-assignments': { href: '/app/tickets/assigned', label: 'View all' },
  // `/maintenance/scheduled` uses the same query and ordering as this panel.
  'maintenance-schedule': { href: '/app/maintenance/scheduled', label: 'View all' },
}

/**
 * Panels the technician dashboard does not show. The server still sends them —
 * the assets API and the assistant's figures are unchanged — but "Under
 * maintenance" and the two asset lists repeat each other and the assets page, so
 * the dashboard keeps one figure, "Assigned to me", and a link to the page.
 */
export const TECHNICIAN_HIDDEN_WIDGETS: readonly string[] = [
  'my-assigned-assets',
  'my-assets-under-maintenance',
]

/** Items dropped from a panel that stays: `my-assets` keeps only "Assigned to me". */
const HIDDEN_ITEMS: Record<string, readonly string[]> = {
  'my-assets': ['maintenance'],
}

/**
 * The widget as the technician dashboard shows it: hidden panels removed, figures
 * and rows given their links, list panels given a "View all". Returns a new
 * object; the server's payload is not mutated.
 */
export function decorateTechnicianWidget(widget: DashboardWidget): DashboardWidget | null {
  if (TECHNICIAN_HIDDEN_WIDGETS.includes(widget.key)) return null

  if (widget.type === 'kpi') {
    const links = TECHNICIAN_CARD_LINKS[widget.key] ?? {}
    const hidden = HIDDEN_ITEMS[widget.key] ?? []

    return {
      ...widget,
      items: widget.items
        .filter((item) => !hidden.includes(item.key))
        .map((item): KpiItem => ({ ...item, href: item.href ?? links[item.key] })),
    }
  }

  if (widget.type === 'list') {
    const rowLink = TECHNICIAN_ROW_LINKS[widget.key]
    const panel = TECHNICIAN_PANEL_LINKS[widget.key]

    return {
      ...widget,
      href: widget.href ?? panel?.href,
      linkLabel: widget.linkLabel ?? panel?.label,
      rows: widget.rows.map((row): ListRow => ({
        ...row,
        href: row.href ?? (rowLink && row.id ? rowLink(row.id) : undefined),
      })),
    }
  }

  return widget
}

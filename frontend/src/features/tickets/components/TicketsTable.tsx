import { Link } from 'react-router-dom'
import {
  Badge,
  isInteractiveTarget,
  SortableTh,
  TBody,
  Table,
  Td,
  THead,
  Th,
  Tr,
} from '@/components/ui'
import { formatDateTime, formatRelative } from '@/lib/datetime'
import type { TicketRow, TicketSortColumn } from '../types'
import {
  ReporterChoiceMarker,
  SlaBadge,
  TicketPriorityBadge,
  TicketStatusBadge,
} from './TicketBadges'

interface TicketsTableProps {
  tickets: TicketRow[]
  sort: TicketSortColumn
  direction: 'asc' | 'desc'
  onSort: (column: TicketSortColumn) => void
  onOpen: (id: string) => void
  /** The technician queue omits the assignee column — it is always them. */
  showTechnician?: boolean
  /**
   * Where a row opens, as a path. When given, the title becomes a real link —
   * keyboard-focusable, announced as a link, middle-click opens a new tab — and
   * the rest of the row stays a pointer target for the same place. A click that
   * began on a nested link or control is left to it. Omitted, the row is
   * mouse-only through `onOpen`, exactly as before; the technician pages opt in.
   */
  hrefFor?: (id: string) => string
}

/**
 * The staff ticket table — the administrator directory and the technician queue
 * (SRS FR-TKT-014, FR-ASN-006).
 *
 * Rows are {@link TicketRow}, the staff projection, which is why the operational
 * columns exist here at all: assignee, deadline and breach posture have no
 * representation in the community card, so this table cannot be rendered for a
 * requester even by mistake.
 *
 * Counts are right-aligned with tabular figures (the Tabular-Truth rule) so the
 * eye can compare them down the column instead of reading each one.
 *
 * Two Phase 2.9 additions are staff-only for the same reason: the reporter's
 * "Other" description beside the category, and a "Reporter's choice" marker
 * under a priority the reporter picked, so self-declared priorities can be
 * found and reviewed (SDD DD-75, DD-77).
 */
export function TicketsTable({
  tickets,
  sort,
  direction,
  onSort,
  onOpen,
  showTechnician = true,
  hrefFor,
}: TicketsTableProps) {
  return (
    <Table>
      <THead>
        <Tr>
          <SortableTh
            label="Ticket"
            column="ticket_number"
            sort={sort}
            direction={direction}
            onSort={onSort}
          />
          <SortableTh
            label="Title"
            column="title"
            sort={sort}
            direction={direction}
            onSort={onSort}
          />
          <SortableTh
            label="Status"
            column="status"
            sort={sort}
            direction={direction}
            onSort={onSort}
          />
          <SortableTh
            label="Priority"
            column="priority"
            sort={sort}
            direction={direction}
            onSort={onSort}
          />
          <SortableTh
            label="Reporter"
            column="reporter"
            sort={sort}
            direction={direction}
            onSort={onSort}
          />
          {showTechnician && (
            <SortableTh
              label="Assigned to"
              column="technician"
              sort={sort}
              direction={direction}
              onSort={onSort}
            />
          )}
          <Th>Equipment &amp; location</Th>
          <SortableTh
            label="Upvotes"
            column="upvotes"
            sort={sort}
            direction={direction}
            onSort={onSort}
            numeric
          />
          <SortableTh
            label="Resolution due"
            column="resolution_due_at"
            sort={sort}
            direction={direction}
            onSort={onSort}
          />
          <SortableTh
            label="Updated"
            column="updated_at"
            sort={sort}
            direction={direction}
            onSort={onSort}
          />
        </Tr>
      </THead>

      <TBody>
        {tickets.map((ticket) => (
          <Tr
            key={ticket.id}
            onClick={(event) => {
              if (hrefFor && isInteractiveTarget(event)) return
              onOpen(ticket.id)
            }}
          >
            <Td className="whitespace-nowrap font-semibold tnum text-ink-strong">
              {ticket.ticket_number}
              {ticket.archived && (
                <Badge tone="outline" className="ml-2">
                  Archived
                </Badge>
              )}
            </Td>

            <Td>
              {hrefFor ? (
                <Link
                  to={hrefFor(ticket.id)}
                  className="block max-w-md truncate rounded-sm font-medium text-ink-strong hover:underline"
                >
                  {ticket.title}
                </Link>
              ) : (
                <span className="block max-w-md truncate font-medium text-ink-strong">
                  {ticket.title}
                </span>
              )}
              {ticket.category.label && (
                <span className="block max-w-md truncate text-xs text-muted">
                  {ticket.category.label}
                  {ticket.category.detail ? ` — ${ticket.category.detail}` : ''}
                </span>
              )}
            </Td>

            <Td>
              <TicketStatusBadge status={ticket.status} />
            </Td>

            <Td>
              <span className="flex flex-col items-start gap-1.5">
                <TicketPriorityBadge priority={ticket.priority} />
                <ReporterChoiceMarker priority={ticket.priority} />
              </span>
            </Td>

            <Td className="text-sm">{ticket.reporter.name ?? '—'}</Td>

            {showTechnician && (
              <Td className="text-sm">
                {ticket.technician?.name ?? <span className="text-muted">Unassigned</span>}
              </Td>
            )}

            <Td className="text-sm">
              {/* At most one of the two is set (SDD DD-79). */}
              {(ticket.pc_unit ?? ticket.asset) && (
                <span className="block font-medium text-ink">
                  {(ticket.pc_unit ?? ticket.asset)?.identifier}
                </span>
              )}
              <span className="block text-muted">
                {ticket.location?.room
                  ? `${ticket.location.room}${ticket.location.building ? `, ${ticket.location.building}` : ''}`
                  : '—'}
              </span>
            </Td>

            <Td className="text-right tnum">{ticket.upvote_count}</Td>

            <Td className="whitespace-nowrap text-sm">
              <span className="block">{formatDateTime(ticket.resolution_due_at)}</span>
              <SlaBadge sla={ticket.sla} className="mt-1" />
            </Td>

            <Td className="whitespace-nowrap text-sm text-muted">
              {formatRelative(ticket.updated_at)}
            </Td>
          </Tr>
        ))}
      </TBody>
    </Table>
  )
}

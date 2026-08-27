import { Badge, SortableTh, TBody, Table, Td, THead, Th, Tr } from '@/components/ui'
import { formatDateTime, formatRelative } from '@/lib/datetime'
import type { TicketRow, TicketSortColumn } from '../types'
import { SlaBadge, TicketPriorityBadge, TicketStatusBadge } from './TicketBadges'

interface TicketsTableProps {
  tickets: TicketRow[]
  sort: TicketSortColumn
  direction: 'asc' | 'desc'
  onSort: (column: TicketSortColumn) => void
  onOpen: (id: string) => void
  /** The technician queue omits the assignee column — it is always them. */
  showTechnician?: boolean
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
 */
export function TicketsTable({
  tickets,
  sort,
  direction,
  onSort,
  onOpen,
  showTechnician = true,
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
          <Tr key={ticket.id} onClick={() => onOpen(ticket.id)}>
            <Td className="whitespace-nowrap font-semibold tnum text-ink-strong">
              {ticket.ticket_number}
              {ticket.archived && (
                <Badge tone="outline" className="ml-2">
                  Archived
                </Badge>
              )}
            </Td>

            <Td>
              <span className="block max-w-md truncate font-medium text-ink-strong">
                {ticket.title}
              </span>
              {ticket.category.label && (
                <span className="block text-xs text-muted">{ticket.category.label}</span>
              )}
            </Td>

            <Td>
              <TicketStatusBadge status={ticket.status} />
            </Td>

            <Td>
              <TicketPriorityBadge priority={ticket.priority} />
            </Td>

            <Td className="text-sm">{ticket.reporter.name ?? '—'}</Td>

            {showTechnician && (
              <Td className="text-sm">
                {ticket.technician?.name ?? <span className="text-muted">Unassigned</span>}
              </Td>
            )}

            <Td className="text-sm">
              {ticket.pc_unit ? (
                <span className="block font-medium text-ink">{ticket.pc_unit.identifier}</span>
              ) : null}
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

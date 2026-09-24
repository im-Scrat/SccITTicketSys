import { Badge, SortableTh, TBody, Table, Td, THead, Th, Tr } from '@/components/ui'
import { formatDate, formatRelative } from '@/lib/datetime'
import type { MaintenanceListItem } from '../types'
import { MaintenanceStatusBadge, MaintenanceTypeBadge, OverdueBadge } from './MaintenanceBadges'

interface MaintenanceTableProps {
  records: MaintenanceListItem[]
  sort: string
  direction: 'asc' | 'desc'
  onSort: (column: string) => void
  onOpen: (id: string) => void
  /** The personal queue omits the technician column — it is always them. */
  showTechnician?: boolean
  /** Only the administrator directory sorts; the queue's order is the point. */
  sortable?: boolean
}

/**
 * The maintenance table — the technician queue, the history list, the preventive
 * horizon and the administrator directory (SRS FR-MNT-003/007/011).
 *
 * One table for four surfaces because they show the same rows to the same kind
 * of reader; what differs is which rows the server sent, and that is decided by
 * `MaintenanceVisibility`, not here.
 *
 * Counts and durations are right-aligned with tabular figures so the eye can
 * compare them down the column instead of reading each one.
 */
export function MaintenanceTable({
  records,
  sort,
  direction,
  onSort,
  onOpen,
  showTechnician = true,
  sortable = true,
}: MaintenanceTableProps) {
  /**
   * The queue's order is deliberate — overdue first, then soonest due — so it is
   * rendered as plain headers there. A queue that can be re-sorted into a
   * comfortable order stops being a queue.
   */
  function header(label: string, column: string, numeric = false) {
    if (!sortable) return <Th>{label}</Th>

    return (
      <SortableTh
        label={label}
        column={column}
        sort={sort}
        direction={direction}
        onSort={onSort}
        numeric={numeric}
      />
    )
  }

  return (
    <Table>
      <THead>
        <Tr>
          {header('Work', 'title')}
          {header('Status', 'status')}
          {header('Type', 'type')}
          {showTechnician && header('Technician', 'technician')}
          <Th>Equipment &amp; location</Th>
          <Th>Checklist</Th>
          {header('Scheduled', 'scheduled_for')}
          {header('Updated', 'updated_at')}
        </Tr>
      </THead>

      <TBody>
        {records.map((record) => (
          <Tr key={record.id} onClick={() => onOpen(record.id)}>
            <Td>
              <span className="block max-w-md truncate font-medium text-ink-strong">
                {record.title}
              </span>
              {record.ticket && (
                <span className="block text-xs text-muted">From {record.ticket.number}</span>
              )}
              {record.archived && (
                <Badge tone="outline" className="mt-1">
                  Archived
                </Badge>
              )}
            </Td>

            <Td>
              <div className="flex flex-wrap items-center gap-1.5">
                <MaintenanceStatusBadge status={record.status} />
                {record.overdue && <OverdueBadge />}
              </div>
            </Td>

            <Td>
              <MaintenanceTypeBadge type={record.type} />
            </Td>

            {showTechnician && (
              <Td className="text-sm">
                {record.technician?.name ?? <span className="text-muted">Unassigned</span>}
              </Td>
            )}

            <Td className="text-sm">
              {record.target ? (
                <span className="block font-medium text-ink">{record.target.identifier}</span>
              ) : null}
              <span className="block text-muted">
                {record.location?.room
                  ? `${record.location.room}${record.location.building ? `, ${record.location.building}` : ''}`
                  : '—'}
              </span>
            </Td>

            <Td className="whitespace-nowrap text-right text-sm tnum">
              {record.checklist.total > 0 ? (
                <>
                  {record.checklist.completed}
                  <span className="text-muted"> / {record.checklist.total}</span>
                </>
              ) : (
                <span className="text-muted">—</span>
              )}
            </Td>

            <Td className="whitespace-nowrap text-sm">{formatDate(record.scheduled_for)}</Td>

            <Td className="whitespace-nowrap text-sm text-muted">
              {formatRelative(record.updated_at)}
            </Td>
          </Tr>
        ))}
      </TBody>
    </Table>
  )
}

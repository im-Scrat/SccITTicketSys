import { Link } from 'react-router-dom'
import { Table, TBody, Td, Th, THead, Tr } from '@/components/ui'
import { formatMinutes, formatRelative } from '@/lib/datetime'
import type { TechnicianSummary } from '../types'

interface TechnicianSummaryTableProps {
  technicians: TechnicianSummary[]
  /** The dashboard shows the essentials; the full page adds history columns. */
  compact?: boolean
  caption: string
}

/**
 * Phase 2.9 — one row per technician. Column groups are headed **Recorded**
 * and **Calculated** so a reader always knows whether a figure was read from a
 * row or derived from timestamps. There is no availability column: nothing in
 * the system records it, and a guess presented as a fact is worse than a gap.
 */
export function TechnicianSummaryTable({
  technicians,
  compact = false,
  caption,
}: TechnicianSummaryTableProps) {
  return (
    <Table>
      <caption className="sr-only">{caption}</caption>
      <THead>
        <Tr>
          <Th scope="col" rowSpan={2}>
            Technician
          </Th>
          <Th scope="colgroup" colSpan={compact ? 2 : 4} className="border-b border-border">
            Recorded
          </Th>
          <Th scope="colgroup" colSpan={compact ? 2 : 4} className="border-b border-border">
            Calculated
          </Th>
        </Tr>
        <Tr>
          <Th scope="col" className="text-right">
            Active
          </Th>
          {!compact && (
            <Th scope="col" className="text-right">
              Completed
            </Th>
          )}
          {!compact && (
            <Th scope="col" className="text-right">
              Maintenance done
            </Th>
          )}
          <Th scope="col">Last activity</Th>
          <Th scope="col" className="text-right">
            Median to accept
          </Th>
          {!compact && (
            <Th scope="col" className="text-right">
              Median to start
            </Th>
          )}
          {!compact && (
            <Th scope="col" className="text-right">
              Median to complete
            </Th>
          )}
          <Th scope="col" className="text-right">
            Overdue
          </Th>
        </Tr>
      </THead>
      <TBody>
        {technicians.map((person) => (
          <Tr key={person.id}>
            <Td>
              <Link
                to={`/app/technicians/${person.id}`}
                className="rounded-sm font-semibold text-ink-strong hover:underline"
              >
                {person.name}
              </Link>
              {person.account_status !== 'active' && (
                <span className="ml-2 text-xs capitalize text-muted">
                  ({person.account_status})
                </span>
              )}
            </Td>
            <Td className="text-right tnum">{person.recorded.active_assignments}</Td>
            {!compact && (
              <Td className="text-right tnum">{person.recorded.completed_assignments}</Td>
            )}
            {!compact && (
              <Td className="text-right tnum">{person.recorded.completed_maintenance}</Td>
            )}
            <Td className="text-muted">
              {person.recorded.last_activity_at
                ? formatRelative(person.recorded.last_activity_at)
                : '—'}
            </Td>
            <Td className="text-right tnum">
              {formatMinutes(person.calculated.median_minutes_to_accept)}
            </Td>
            {!compact && (
              <Td className="text-right tnum">
                {formatMinutes(person.calculated.median_minutes_to_start)}
              </Td>
            )}
            {!compact && (
              <Td className="text-right tnum">
                {formatMinutes(person.calculated.median_minutes_to_complete)}
              </Td>
            )}
            <Td className="text-right tnum">
              {person.calculated.sla_breached > 0 ? (
                <span className="font-semibold text-danger-strong">
                  {person.calculated.sla_breached}
                </span>
              ) : (
                0
              )}
            </Td>
          </Tr>
        ))}
      </TBody>
    </Table>
  )
}

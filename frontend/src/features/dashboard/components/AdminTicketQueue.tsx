import { UserPlus } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Alert, Button, Skeleton } from '@/components/ui'
import { AssignDialog } from '@/features/tickets/components/AdminTicketActions'
import { TicketPriorityBadge, TicketStatusBadge } from '@/features/tickets/components/TicketBadges'
import { useTicketDirectory, useTicketOptions } from '@/features/tickets/hooks/queries'
import type { TicketParams, TicketRow } from '@/features/tickets/types'
import { formatRelative } from '@/lib/datetime'
import { WidgetCard } from './WidgetCard'

interface AdminTicketQueueProps {
  title: string
  description: string
  params: TicketParams
  /** Where "Open" goes — the management directory with the same filter. */
  href: string
  empty: string
}

/**
 * Phase 2.9 — a short, actionable ticket queue for the administrator's
 * dashboard ("Escalated by the reporter", "Needs an owner").
 *
 * Read from the administrative directory endpoint — so it shows exactly what
 * the management page would — and each row carries the same Assign dialog as
 * the ticket page, so the commonest administrator action needs no navigation.
 * Kept live by the admin dashboard channel, which invalidates the directory.
 */
export function AdminTicketQueue({
  title,
  description,
  params,
  href,
  empty,
}: AdminTicketQueueProps) {
  const query = useTicketDirectory({ per_page: 6, ...params })
  const options = useTicketOptions()
  const [assigning, setAssigning] = useState<TicketRow | null>(null)
  const rows = query.data?.data ?? []
  const total = query.data?.meta.total ?? rows.length

  return (
    <WidgetCard
      title={`${title}${query.data ? ` · ${total.toLocaleString()}` : ''}`}
      description={description}
      href={href}
      linkLabel="Open all"
    >
      {query.isError ? (
        <Alert tone="error">This queue could not be loaded.</Alert>
      ) : query.isLoading ? (
        <div className="flex flex-col gap-2">
          <Skeleton className="h-14" />
          <Skeleton className="h-14" />
        </div>
      ) : rows.length === 0 ? (
        <p className="py-3 text-sm text-muted">{empty}</p>
      ) : (
        <ul className="-mx-1 flex flex-col divide-y divide-border">
          {rows.map((row) => (
            <li key={row.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-1 py-3">
              <div className="min-w-0 flex-1">
                <Link
                  to={`/app/tickets/manage/${row.id}`}
                  className="rounded-sm text-sm font-semibold text-ink-strong hover:underline"
                >
                  <span className="tnum text-muted">{row.ticket_number}</span> {row.title}
                </Link>
                <div className="mt-1.5 flex flex-wrap items-center gap-2 text-xs text-muted">
                  <TicketStatusBadge status={row.status} />
                  <TicketPriorityBadge priority={row.priority} />
                  {row.location?.room && <span>{row.location.room}</span>}
                  <span>· {formatRelative(row.created_at)}</span>
                </div>
              </div>
              <Button
                variant="secondary"
                size="sm"
                leftIcon={<UserPlus size={18} aria-hidden="true" />}
                onClick={() => setAssigning(row)}
                aria-label={`${row.technician ? 'Reassign' : 'Assign'} ${row.ticket_number}`}
              >
                {row.technician ? 'Reassign' : 'Assign'}
              </Button>
            </li>
          ))}
        </ul>
      )}

      {assigning && (
        <AssignDialog
          open
          onClose={() => setAssigning(null)}
          ticket={{ id: assigning.id, is_assigned: assigning.technician !== null }}
          options={options.data}
        />
      )}
    </WidgetCard>
  )
}

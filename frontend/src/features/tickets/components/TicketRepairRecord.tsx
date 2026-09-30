import { useQueryClient } from '@tanstack/react-query'
import { Wrench } from 'lucide-react'
import { useEffect, useId, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Alert, Button, ButtonLink } from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { MaintenanceStatusBadge } from '@/features/maintenance/components/MaintenanceBadges'
import { useCreateMaintenance } from '@/features/maintenance/hooks/mutations'
import type { ConcurrentWarning } from '@/features/maintenance/types'
import { formatDate } from '@/lib/datetime'
import { ticketsKeys } from '../hooks/queries'
import type { TicketDetail, TicketRepairMeta } from '../types'

interface TicketRepairRecordProps {
  ticket: Pick<TicketDetail, 'id' | 'ticket_number' | 'title' | 'pc_unit'>
  repair: TicketRepairMeta
  readOnly: boolean
}

/** The server's column is 255 characters; the ticket title alone can approach it. */
const TITLE_LIMIT = 255

/**
 * The repair this ticket produced, as the technician working it (WP-K; SRS
 * UC-03 "(optional) create maintenance record → resolve").
 *
 * **The record is the Maintenance module's, not a second copy.** Diagnosis,
 * root cause, parts, notes and photos are captured on the real maintenance
 * record — on the machine's history, where the next person to work on it will
 * look — and this section only lists those records and opens a new one linked
 * to the ticket. Nothing about the repair is stored on the ticket itself.
 *
 * `can_start` is the server's whole answer (active assignment, a PC to hold the
 * history, no record already open, `maintenance.create`); the client never
 * re-derives it. The server also refuses a link to a ticket this technician is
 * not working, so the button is a convenience, not the control.
 *
 * **Other open work on the machine stops the hand-off.** Creation can report
 * maintenance already open against the same PC (`meta.concurrent`, the warning
 * the Maintenance module's own create page shows). The record is created either
 * way; the technician is shown what else is open before going to it, and focus
 * moves to the way on — the start button has gone by then, so focus would
 * otherwise fall back to the page.
 *
 * Renders nothing when there is nothing to list and nothing to do — a finished
 * ticket that never needed a record should not carry an empty box.
 */
export function TicketRepairRecord({ ticket, repair, readOnly }: TicketRepairRecordProps) {
  const headingId = useId()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const create = useCreateMaintenance()
  const [created, setCreated] = useState<{ id: string; concurrent: ConcurrentWarning[] } | null>(
    null,
  )
  const continueId = useId()

  useEffect(() => {
    if (created) document.getElementById(continueId)?.focus()
  }, [created, continueId])

  const { records, can_start: canStart } = repair
  const pcUnit = ticket.pc_unit
  const hasOpenRecord = records.some((record) => record.status.is_open)

  // A ticket naming no machine cannot hold a record (the record needs a
  // target); say so while the work is live, so the absence of a button is
  // explained rather than mysterious.
  const explainNoPc = !pcUnit && !readOnly

  if (records.length === 0 && !canStart && !explainNoPc && !created) return null

  const start = () => {
    if (!pcUnit?.id) return
    create.mutate(
      {
        title: `Repair — ${ticket.ticket_number}: ${ticket.title}`.slice(0, TITLE_LIMIT),
        type: 'corrective',
        pc_unit: pcUnit.id,
        ticket: ticket.id,
      },
      {
        onSuccess: (result) => {
          // The ticket's repair list changed too; the maintenance hook only
          // refreshes its own namespace.
          void queryClient.invalidateQueries({ queryKey: ticketsKeys.all })

          const concurrent = result.meta?.concurrent ?? []
          if (concurrent.length === 0) {
            navigate(`/app/maintenance/${result.data.id}`)
          } else {
            setCreated({ id: result.data.id, concurrent })
          }
        },
      },
    )
  }

  return (
    <section
      aria-labelledby={headingId}
      className="flex flex-col gap-4 rounded-lg border-2 border-border bg-surface p-6"
    >
      <div className="flex flex-col gap-1">
        <h2 id={headingId} className="text-base font-bold text-ink-strong">
          Repair record
        </h2>
        {pcUnit ? (
          <p className="measure text-sm text-muted">
            Diagnosis, root cause, parts replaced and photos go on a maintenance record in{' '}
            <span className="font-semibold text-ink">{pcUnit.label}</span>&rsquo;s history, where
            the next person to work on it will look.
            {hasOpenRecord &&
              !readOnly &&
              ' Complete the open record before you mark this ticket done.'}
          </p>
        ) : (
          <p className="measure text-sm text-muted">
            This ticket names no PC, so there is no machine history to record the repair in. Put
            what you found and what you did in a comment instead.
          </p>
        )}
      </div>

      {records.length > 0 && (
        <ul className="flex flex-col divide-y divide-border border-y border-border">
          {records.map((record) => (
            <li key={record.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 py-3">
              <Link
                to={`/app/maintenance/${record.id}`}
                className="min-w-0 flex-1 basis-60 rounded-sm font-semibold break-words text-ink-strong underline underline-offset-4 hover:text-primary-strong"
              >
                {record.title}
              </Link>
              <MaintenanceStatusBadge status={record.status} />
              {record.completed_at && (
                <span className="text-sm text-muted tabular-nums">
                  Completed {formatDate(record.completed_at)}
                </span>
              )}
            </li>
          ))}
        </ul>
      )}

      {created && (
        <Alert tone="warning" title="This machine already has open maintenance">
          <p className="mb-3">
            Your repair record was created. These are also open against the same PC — check you are
            not duplicating one of them.
          </p>
          <ul className="mb-4 flex flex-col gap-1">
            {created.concurrent.map((item) => (
              <li key={item.id} className="text-sm">
                <span className="font-semibold">{item.title}</span>
                <span className="text-muted">
                  {' · '}
                  {item.status_label}
                  {item.technician && ` · ${item.technician}`}
                  {item.scheduled_for && ` · ${formatDate(item.scheduled_for)}`}
                </span>
              </li>
            ))}
          </ul>
          <ButtonLink id={continueId} variant="secondary" to={`/app/maintenance/${created.id}`}>
            Continue to my repair record
          </ButtonLink>
        </Alert>
      )}

      {canStart && !readOnly && !created && pcUnit?.id && (
        <div>
          <Button
            variant="secondary"
            leftIcon={<Wrench size={20} aria-hidden="true" />}
            loading={create.isPending}
            disabled={create.isPending}
            onClick={start}
          >
            {records.length > 0 ? 'Start another repair record' : 'Start a repair record'}
          </Button>
        </div>
      )}

      {create.isError && (
        <Alert tone="error" title="The repair record was not created">
          {getErrorMessage(create.error)}
        </Alert>
      )}
    </section>
  )
}

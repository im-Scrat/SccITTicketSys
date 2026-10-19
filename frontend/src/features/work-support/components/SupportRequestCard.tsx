import { CalendarClock, MessageSquare, Monitor, XCircle } from 'lucide-react'
import type { ReactNode } from 'react'
import { Surface } from '@/components/ui'
import { formatDateTime } from '@/lib/datetime'
import { SupportEvidenceGallery } from './SupportEvidenceGallery'
import { SupportRequestStatusPill } from './SupportRequestStatusPill'
import type { SupportRequest } from '../types'

/**
 * One request, rendered the same way on both surfaces.
 *
 * The technician's tracking page and the administrator's inbox show the **same
 * card**, and that is deliberate: the two roles differ in which requests they
 * can reach, not in what a request looks like. Two card components would have
 * been two places to add a field and one place to forget it — and the field
 * most likely to be forgotten is the decline reason, which is the one a
 * technician most needs to read.
 *
 * `actions` is a slot rather than a prop bag of booleans, so each page supplies
 * the buttons its own role has — and neither page can accidentally render the
 * other's.
 */
export function SupportRequestCard({
  request,
  showTechnician = false,
  actions,
}: {
  request: SupportRequest
  /** The inbox names who asked; a technician's own page does not need to. */
  showTechnician?: boolean
  actions?: ReactNode
}) {
  const { decision } = request

  return (
    <Surface as="article" className="p-5 sm:p-6">
      <div className="flex flex-col gap-4">
        {/* ------------------------------------------------------- heading */}
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="flex min-w-0 flex-col gap-1">
            <p className="flex items-center gap-2 text-sm font-semibold text-ink">
              <Monitor className="size-4 shrink-0 text-muted" aria-hidden="true" />
              <span className="font-mono">{request.pc_unit?.unit_code ?? 'Unknown unit'}</span>
              {request.pc_unit?.room && (
                <span className="truncate font-normal text-muted">· {request.pc_unit.room}</span>
              )}
            </p>

            {showTechnician && request.technician && (
              <p className="text-xs text-muted">Asked by {request.technician.name}</p>
            )}

            {request.submitted_at && (
              <p className="text-xs text-muted">{formatDateTime(request.submitted_at)}</p>
            )}
          </div>

          <SupportRequestStatusPill status={request.status} label={request.status_label} />
        </div>

        {/* --------------------------------------------------------- items */}
        <ul className="flex flex-col gap-1.5">
          {request.items.map((item, index) => (
            <li key={`${item.name}-${index}`} className="flex items-baseline gap-2 text-sm">
              <span className="font-mono text-xs text-muted">×{item.quantity}</span>
              <span className="text-ink">{item.name}</span>
              {item.remarks && <span className="text-xs text-muted">— {item.remarks}</span>}
            </li>
          ))}
        </ul>

        {/* --------------------------------------------------- explanation */}
        <p className="max-w-[70ch] text-sm leading-relaxed text-pretty text-ink">
          {request.explanation}
        </p>

        {/* ------------------------------------------------------- context */}
        {(request.maintenance || request.ticket) && (
          <div className="flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted">
            {request.maintenance && <span>Job: {request.maintenance.title}</span>}
            {request.ticket && <span>Ticket {request.ticket.number}</span>}
          </div>
        )}

        {/*
          The evidence itself, not a count of it (FR-WSR-005). Stage E could
          only say "1 attachment"; an administrator deciding on a photograph
          they cannot open is deciding on a bare notification, which is the one
          thing that requirement rules out.
        */}
        <SupportEvidenceGallery requestId={request.id} attachments={request.attachments} />

        {/* ------------------------------------------------------ decision */}
        {(decision.rescheduled_to ||
          decision.clarification_reason ||
          decision.decline_reason ||
          decision.cancelled_at) && (
          <div className="flex flex-col gap-3 border-t border-border pt-4">
            {/*
              All three decision shapes render together rather than behind a
              switch on the status. A request that was asked about face-to-face
              and *then* declined shows both — which is FR-WSR-004's "no prior
              workflow state overwritten in place", made visible rather than
              merely stored.
            */}
            {decision.clarification_reason && (
              <Outcome
                icon={<MessageSquare className="size-4" aria-hidden="true" />}
                tone="warning"
                title="Face-to-face discussion requested"
              >
                {decision.clarification_reason}
                {decision.proposed_meeting_at && (
                  <span className="mt-1 block text-xs">
                    Suggested: {formatDateTime(decision.proposed_meeting_at)}
                  </span>
                )}
              </Outcome>
            )}

            {decision.rescheduled_to && (
              <Outcome
                icon={<CalendarClock className="size-4" aria-hidden="true" />}
                tone="success"
                title={`Approved — work rescheduled to ${formatDateTime(decision.rescheduled_to)}`}
              >
                {decision.reschedule_reason}
                {decision.acknowledged_at && (
                  <span className="mt-1 block text-xs">
                    You acknowledged this on {formatDateTime(decision.acknowledged_at)}.
                  </span>
                )}
              </Outcome>
            )}

            {decision.decline_reason && (
              <Outcome
                icon={<XCircle className="size-4" aria-hidden="true" />}
                tone="danger"
                title="Declined"
              >
                {decision.decline_reason}
              </Outcome>
            )}

            {decision.cancelled_at && (
              <Outcome
                icon={<XCircle className="size-4" aria-hidden="true" />}
                tone="neutral"
                title={`Withdrawn${decision.cancelled_by ? ` by ${decision.cancelled_by}` : ''}`}
              >
                {decision.cancellation_note}
              </Outcome>
            )}

            {decision.by && decision.at && (
              <p className="text-xs text-muted">
                Decided by {decision.by.name} on {formatDateTime(decision.at)}
              </p>
            )}
          </div>
        )}

        {actions && <div className="flex flex-wrap gap-2 pt-1">{actions}</div>}
      </div>
    </Surface>
  )
}

const outcomeTones = {
  success: 'text-success-strong',
  warning: 'text-warning-strong',
  danger: 'text-danger-strong',
  neutral: 'text-muted',
} as const

function Outcome({
  icon,
  tone,
  title,
  children,
}: {
  icon: ReactNode
  tone: keyof typeof outcomeTones
  title: string
  children?: ReactNode
}) {
  return (
    <div className="flex gap-2.5">
      <span className={`mt-0.5 shrink-0 ${outcomeTones[tone]}`}>{icon}</span>
      <div className="flex min-w-0 flex-col gap-0.5">
        <p className={`text-sm font-semibold ${outcomeTones[tone]}`}>{title}</p>
        {children && (
          <div className="max-w-[65ch] text-sm leading-relaxed text-pretty text-ink">
            {children}
          </div>
        )}
      </div>
    </div>
  )
}

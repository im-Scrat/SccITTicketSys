import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Alert, Badge } from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { formatDateTime } from '@/lib/datetime'
import type { SlaPosture, TicketDetail } from '../types'
import { SlaBadge, TicketPriorityBadge, TicketStatusBadge } from './TicketBadges'

interface TicketSummaryProps {
  ticket: TicketDetail
  sla?: SlaPosture | null
  /** Administrators can open the PC's asset record; nobody else can. */
  linkEquipment?: boolean
}

/**
 * The head of a ticket page: what the fault is, where it is, and where it stands.
 *
 * Everything rendered here is already redacted by the server for the caller —
 * `technician`, `sla` and `ai` arrive as `null` for a requester because
 * {@link TicketDetailResource} refuses to populate them, not because this
 * component hides them. So a field that is absent is absent from the response,
 * and there is nothing here for a browser devtools panel to reveal.
 */
export function TicketSummary({ ticket, sla = null, linkEquipment = false }: TicketSummaryProps) {
  const { hasRole } = useAuth()
  const isAdministrator = hasRole('administrator')

  return (
    <div className="flex flex-col gap-8">
      <div className="flex flex-col gap-4">
        <div className="flex flex-wrap items-center gap-3">
          <span className="text-sm font-bold tnum text-ink-strong">{ticket.ticket_number}</span>
          <TicketStatusBadge status={ticket.status} />
          <TicketPriorityBadge priority={ticket.priority} />
          {ticket.category.label && <Badge tone="outline">{ticket.category.label}</Badge>}
          <SlaBadge sla={sla} />
          {ticket.archived && <Badge tone="outline">Archived</Badge>}
        </div>

        <h1 className="text-2xl font-bold text-ink-strong">{ticket.title}</h1>
      </div>

      {ticket.duplicate_of && (
        <Alert tone="info" title="This has been marked as a duplicate">
          The work is being tracked on{' '}
          <Link to={`/app/tickets/${ticket.duplicate_of.id}`} className="font-semibold underline">
            {ticket.duplicate_of.ticket_number} — {ticket.duplicate_of.title}
          </Link>
          .
        </Alert>
      )}

      <section>
        <h2 className="text-base font-bold text-ink-strong">What is wrong</h2>
        <p className="measure mt-3 whitespace-pre-wrap text-base text-ink">{ticket.description}</p>
      </section>

      {ticket.tags && ticket.tags.length > 0 && (
        <div className="flex flex-wrap items-center gap-3">
          {ticket.tags.map((tag) => (
            <Badge key={tag.slug} tone="neutral">
              {tag.label}
            </Badge>
          ))}
        </div>
      )}

      <section>
        <h2 className="text-base font-bold text-ink-strong">Details</h2>
        <dl className="mt-4 grid gap-x-8 gap-y-5 sm:grid-cols-2 xl:grid-cols-3">
          <Detail label="Reported by">
            {ticket.reporter?.name ?? '—'}
            {ticket.reporter?.email && (
              <span className="block text-sm text-muted">{ticket.reporter.email}</span>
            )}
          </Detail>

          <Detail label="Reported">{formatDateTime(ticket.reported_at)}</Detail>

          <Detail label="Affected PC">
            {ticket.pc_unit ? (
              linkEquipment && ticket.pc_unit.id ? (
                <Link
                  to={`/app/assets/pc-units/${ticket.pc_unit.id}`}
                  className="font-semibold underline"
                >
                  {ticket.pc_unit.label} ({ticket.pc_unit.identifier})
                </Link>
              ) : (
                <>
                  {ticket.pc_unit.label} ({ticket.pc_unit.identifier})
                </>
              )
            ) : (
              'Not about a specific PC'
            )}
          </Detail>

          <Detail label="Location">
            {ticket.location?.room
              ? [ticket.location.room, ticket.location.floor, ticket.location.building]
                  .filter(Boolean)
                  .join(' · ')
              : '—'}
          </Detail>

          <Detail label="Being worked on by">
            {/*
              A requester is told *that* someone has it, never who: the
              `technician` field is null in their response. Naming the person
              invites the ticket to be chased personally instead of through the
              service desk.
            */}
            {ticket.technician?.name ??
              (ticket.is_assigned ? 'Assigned to the IT team' : 'Not yet assigned')}
          </Detail>

          <Detail label="Upvotes">
            <span className="tnum">{ticket.upvote_count}</span>
          </Detail>

          {ticket.resolved_at && (
            <Detail label="Resolved">{formatDateTime(ticket.resolved_at)}</Detail>
          )}
          {ticket.closed_at && <Detail label="Closed">{formatDateTime(ticket.closed_at)}</Detail>}
          {ticket.reopened_at && (
            <Detail label="Reopened">{formatDateTime(ticket.reopened_at)}</Detail>
          )}

          {ticket.sla && (
            <>
              <Detail label="First response due">
                {formatDateTime(ticket.sla.response_due_at)}
              </Detail>
              <Detail label="Resolution due">{formatDateTime(ticket.sla.resolution_due_at)}</Detail>
            </>
          )}
        </dl>
      </section>

      {isAdministrator && ticket.ai && (
        <section className="rounded-lg border-2 border-border bg-surface-sunken p-6">
          <h2 className="text-base font-bold text-ink-strong">Automated analysis</h2>
          {ticket.ai.analysed ? (
            <div className="mt-3 flex flex-col gap-3">
              <p className="measure text-base text-ink">{ticket.ai.summary}</p>
              <p className="text-sm text-muted">
                {ticket.ai.confidence !== null && (
                  <>Confidence {Math.round(ticket.ai.confidence * 100)}% · </>
                )}
                {ticket.ai.estimated_minutes !== null
                  ? `Estimated ${ticket.ai.estimated_minutes} minutes`
                  : 'No time estimate'}
                {ticket.ai.technician_required ? ' · A technician is needed' : ''}
              </p>
            </div>
          ) : (
            /*
              Stated plainly rather than faked or hidden. The columns exist and
              the panel is wired; the analysis job is a later phase, and an
              administrator should be able to see that the feature is coming
              rather than wonder whether it silently failed.
            */
            <p className="measure mt-3 text-base text-muted">
              Not analysed. Automatic summarising and triage arrive with the AI work package — until
              then this ticket is triaged by hand, exactly as it reads above.
            </p>
          )}
        </section>
      )}
    </div>
  )
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt className="text-sm font-semibold text-muted">{label}</dt>
      <dd className="mt-1 text-base text-ink">{children}</dd>
    </div>
  )
}

import { MapPin, MessageSquare, Monitor } from 'lucide-react'
import { Link } from 'react-router-dom'
import { Badge } from '@/components/ui'
import { formatRelative } from '@/lib/datetime'
import type { TicketCard } from '../types'
import { TicketPriorityBadge, TicketStatusBadge } from './TicketBadges'
import { VoteButton } from './VoteButton'

interface TicketCardItemProps {
  ticket: TicketCard
  /** Where the title links to — the feed and "my tickets" open the same page. */
  to?: string
  /** Voting is closed once the ticket is (FR-TKT-009). */
  canVote?: boolean
  compact?: boolean
}

/**
 * One report in the community feed (SRS UCS-02, FR-TKT-009/013).
 *
 * Everything on this card comes from {@link TicketCard} — the restricted
 * projection — so there is structurally nothing here to leak: no description
 * beyond the server's excerpt, no attachments, no technician, no SLA. A reader
 * sees enough to recognise "someone already reported this" and to add their
 * weight to it, which is the entire reason a requester sees anyone else's ticket.
 *
 * The vote sits on the left as a tall target rather than a text link, because it
 * is the one action this card exists to invite.
 */
export function TicketCardItem({
  ticket,
  to,
  canVote = true,
  compact = false,
}: TicketCardItemProps) {
  const href = to ?? `/app/tickets/${ticket.id}`
  const votable = canVote && ticket.status.is_open

  return (
    <article className="flex gap-5 rounded-lg border-2 border-border bg-surface p-6">
      <VoteButton
        ticketId={ticket.id}
        upvoteCount={ticket.upvote_count}
        hasVoted={ticket.has_voted}
        disabled={!votable}
        size={compact ? 'sm' : 'md'}
      />

      <div className="flex min-w-0 flex-1 flex-col gap-3">
        <div className="flex flex-wrap items-center gap-3">
          <TicketStatusBadge status={ticket.status} />
          <TicketPriorityBadge priority={ticket.priority} />
          {ticket.category.label && <Badge tone="outline">{ticket.category.label}</Badge>}
          {ticket.is_mine && <Badge tone="primary">Your report</Badge>}
        </div>

        <h3 className="text-lg font-bold text-ink-strong">
          <Link to={href} className="rounded-sm hover:underline">
            {ticket.title}
          </Link>
        </h3>

        {!compact && ticket.excerpt && (
          <p className="measure text-base text-ink">{ticket.excerpt}</p>
        )}

        <dl className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-muted">
          <div className="flex items-center gap-2">
            <dt className="sr-only">Reference</dt>
            <dd className="font-semibold tnum text-ink">{ticket.ticket_number}</dd>
          </div>

          {ticket.pc_unit && (
            <div className="flex items-center gap-2">
              <Monitor size={20} aria-hidden="true" />
              <dt className="sr-only">Affected PC</dt>
              <dd>
                {ticket.pc_unit.label} ({ticket.pc_unit.identifier})
              </dd>
            </div>
          )}

          {ticket.location?.room && (
            <div className="flex items-center gap-2">
              <MapPin size={20} aria-hidden="true" />
              <dt className="sr-only">Location</dt>
              <dd>
                {ticket.location.room}
                {ticket.location.building ? `, ${ticket.location.building}` : ''}
              </dd>
            </div>
          )}

          <div className="flex items-center gap-2">
            <MessageSquare size={20} aria-hidden="true" />
            <dt className="sr-only">Comments</dt>
            <dd className="tnum">
              {ticket.comment_count} {ticket.comment_count === 1 ? 'comment' : 'comments'}
            </dd>
          </div>

          <div className="flex items-center gap-2">
            <dt className="sr-only">Reported</dt>
            <dd>
              {/* A display name only — the projection carries no contact detail. */}
              {ticket.reporter.name ?? 'Someone'} · {formatRelative(ticket.created_at)}
            </dd>
          </div>
        </dl>
      </div>
    </article>
  )
}

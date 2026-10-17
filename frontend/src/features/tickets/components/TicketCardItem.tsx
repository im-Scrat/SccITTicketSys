import { MapPin, MessageSquare, Monitor, Package } from 'lucide-react'
import { useId, useState } from 'react'
import { Link } from 'react-router-dom'
import { Badge, Button } from '@/components/ui'
import { cn } from '@/lib/cn'
import { formatRelative } from '@/lib/datetime'
import type { TicketCard } from '../types'
import { TicketPriorityBadge, TicketStatusBadge } from './TicketBadges'
import { TicketCommentsDialog } from './TicketCommentsDialog'
import { VoteButton } from './VoteButton'

interface TicketCardItemProps {
  ticket: TicketCard
  /** Where the card links to — the feed and "my tickets" open the same page. */
  to?: string
  /** Voting is closed once the ticket is (FR-TKT-009). */
  canVote?: boolean
  /**
   * A row shown inside the report form (similar reports, "already reported on
   * this PC"). It keeps a title-only link and no comments button: a stray
   * click anywhere on it must not abandon a half-written report. It draws no
   * card border of its own — the caller's list draws the dividers — because
   * the form's sections are already cards.
   */
  compact?: boolean
}

/**
 * One report in the community feed (SRS UCS-02, FR-TKT-007/009/013).
 *
 * Everything on this card comes from {@link TicketCard} — the restricted
 * projection — so there is structurally nothing here to leak: no description
 * beyond the server's excerpt, no attachments, no technician, no SLA. A reader
 * sees enough to recognise "someone already reported this" and to add their
 * weight to it, which is the entire reason a requester sees anyone else's ticket.
 *
 * The vote sits on the left as a tall target rather than a text link, because it
 * is the one action this card exists to invite.
 *
 * **The whole card opens the ticket.** The title link is stretched over the card
 * with a pseudo-element, rather than an `onClick` on the `<article>`: the card
 * stays one real link (middle-click and "open in new tab" work, a screen reader
 * hears the title once), and the two controls that do something else — the vote
 * and the comments button — sit above that layer (`z-10`) so a click on them is
 * never also a navigation. A disabled vote therefore does nothing, rather than
 * unexpectedly opening the ticket.
 */
export function TicketCardItem({
  ticket,
  to,
  canVote = true,
  compact = false,
}: TicketCardItemProps) {
  const href = to ?? `/app/tickets/${ticket.id}`
  const votable = canVote && ticket.status.is_open
  const titleId = useId()
  const [commentsOpen, setCommentsOpen] = useState(false)

  const commentLabel = `${ticket.comment_count} ${ticket.comment_count === 1 ? 'comment' : 'comments'}`

  return (
    <article
      className={cn(
        compact
          ? 'flex gap-4 p-4'
          : 'relative flex gap-5 rounded-lg border-2 border-border bg-surface p-6 transition-colors duration-150 [transition-timing-function:var(--ease-standard)] has-[[data-card-link]:hover]:border-control-border',
      )}
    >
      {/* Lifted above the stretched link; `self-start` keeps the lifted box
          the size of the button, so the strip beneath it still opens the card. */}
      <div className="relative z-10 shrink-0 self-start">
        <VoteButton
          ticketId={ticket.id}
          upvoteCount={ticket.upvote_count}
          hasVoted={ticket.has_voted}
          disabled={!votable}
          size={compact ? 'sm' : 'md'}
        />
      </div>

      <div className="flex min-w-0 flex-1 flex-col gap-3">
        <div className="flex flex-wrap items-center gap-3">
          <TicketStatusBadge status={ticket.status} />
          <TicketPriorityBadge priority={ticket.priority} />
          {ticket.category.label && <Badge tone="outline">{ticket.category.label}</Badge>}
          {ticket.is_mine && <Badge tone="primary">Your report</Badge>}
        </div>

        <h3
          id={titleId}
          className={cn('font-bold text-ink-strong', compact ? 'text-base' : 'text-lg')}
        >
          <Link
            to={href}
            data-card-link={compact ? undefined : ''}
            className={cn(
              'rounded-sm',
              // Only a compact card underlines its title: there the title is
              // the whole link. On a full card the stretched layer would show the
              // underline wherever the pointer is, reading as if the title alone
              // were the target — the darker card border is the cue instead.
              compact && 'hover:underline',
              // The card is the target, so the focus ring is drawn around the
              // card (on the stretched layer), not around the title text.
              !compact &&
                'after:absolute after:inset-0 after:rounded-lg focus-visible:outline-none focus-visible:after:outline-3 focus-visible:after:outline-offset-2 focus-visible:after:outline-focus',
            )}
          >
            {ticket.title}
          </Link>
        </h3>

        {!compact && ticket.excerpt && (
          <p className="measure text-base text-ink">{ticket.excerpt}</p>
        )}

        <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
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

            {ticket.asset && (
              <div className="flex items-center gap-2">
                <Package size={20} aria-hidden="true" />
                <dt className="sr-only">Affected item</dt>
                <dd>
                  {ticket.asset.label} ({ticket.asset.identifier})
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

            {/* On a full card the count is the comments button's label instead. */}
            {compact && (
              <div className="flex items-center gap-2">
                <MessageSquare size={20} aria-hidden="true" />
                <dt className="sr-only">Comments</dt>
                <dd className="tnum">{commentLabel}</dd>
              </div>
            )}

            <div className="flex items-center gap-2">
              <dt className="sr-only">Reported</dt>
              <dd>
                {/* The server decides what this viewer may know: "You", "A
                    colleague", or (Administrators only) the name. */}
                {ticket.reporter.label} · {formatRelative(ticket.created_at)}
              </dd>
            </div>
          </dl>

          {!compact && (
            <Button
              variant="secondary"
              size="sm"
              className="z-10"
              onClick={() => setCommentsOpen(true)}
              aria-haspopup="dialog"
              aria-describedby={titleId}
              leftIcon={<MessageSquare size={20} aria-hidden="true" />}
            >
              <span className="tnum">{ticket.comment_count === 0 ? 'Comment' : commentLabel}</span>
            </Button>
          )}
        </div>
      </div>

      {!compact && (
        <TicketCommentsDialog
          ticket={ticket}
          href={href}
          open={commentsOpen}
          onClose={() => setCommentsOpen(false)}
        />
      )}
    </article>
  )
}

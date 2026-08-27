import { ArrowLeft, Pencil } from 'lucide-react'
import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Alert, Badge, Button, PageLoader, Tabs } from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { formatRelative } from '@/lib/datetime'
import { TicketAttachments } from '../components/TicketAttachments'
import { TicketPriorityBadge, TicketStatusBadge } from '../components/TicketBadges'
import { TicketComments } from '../components/TicketComments'
import { TicketEditDrawer } from '../components/TicketEditDrawer'
import { TicketStatusActions } from '../components/TicketStatusActions'
import { TicketSummary } from '../components/TicketSummary'
import { VoteButton } from '../components/VoteButton'
import { useTicket } from '../hooks/queries'
import { isFullTicket, type TicketCard } from '../types'

/**
 * One ticket, as the person who reported it — or anyone in the community — sees
 * it (SRS FR-TKT-012/013).
 *
 * **This route serves two projections and the client cannot choose between
 * them.** The server decides from the caller's relationship to the ticket, and
 * `isFullTicket` is the only way the page finds out which arrived. A requester
 * who pastes someone else's uuid gets the community card here for exactly the
 * same reason they see the card in the feed — one visibility service answers
 * both, so a url can never yield more than a list would have.
 *
 * The reporter-owned lifecycle moves (confirm, reopen, cancel) are rendered from
 * `meta.transitions`, so the buttons are whatever `TicketLifecycle` says this
 * person may do, phrased in the reporter's terms rather than the system's.
 */
export default function TicketDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { hasPermission } = useAuth()
  const { data, isLoading, isError, error } = useTicket(id)

  const [tab, setTab] = useState('details')
  const [editing, setEditing] = useState(false)

  useDocumentMeta({ title: data ? data.data.ticket_number : 'Ticket' })

  if (isLoading) return <PageLoader />

  if (isError || !data) {
    return (
      <div className="flex flex-col gap-8">
        <BackLink onClick={() => navigate('/app/tickets')} />
        <Alert tone="error" title="This ticket could not be opened">
          {isNotFound(error)
            ? 'It may have been removed, or it may not be one you have access to.'
            : 'Refresh the page to try again.'}
        </Alert>
      </div>
    )
  }

  /*
   * The restricted view. Everything a community reader gets is on the card
   * itself — there is no description, no evidence and no internal note in this
   * response to render even if this component tried.
   */
  if (!isFullTicket(data)) {
    return (
      <CommunityView
        ticket={data.data}
        canComment={hasPermission('tickets.comment')}
        onBack={() => navigate('/app/tickets')}
      />
    )
  }

  const ticket = data.data
  const meta = data.meta ?? {}
  const can = meta.can
  const transitions = meta.transitions ?? []

  const tabs = [
    { value: 'details', label: 'Details' },
    { value: 'comments', label: 'Comments', count: ticket.comment_count },
    { value: 'files', label: 'Photos & files', count: ticket.attachment_count },
  ]

  return (
    <div className="flex flex-col gap-8">
      <BackLink onClick={() => navigate('/app/tickets')} />

      <div className="flex flex-wrap items-start justify-between gap-6">
        <div className="min-w-0 flex-1">
          <TicketSummary ticket={ticket} sla={meta.sla ?? null} />
        </div>

        <div className="flex shrink-0 flex-col items-end gap-4">
          <VoteButton
            ticketId={ticket.id}
            upvoteCount={ticket.upvote_count}
            hasVoted={ticket.has_voted}
            disabled={!can?.vote || !ticket.status.is_open}
          />
          {can?.update && (
            <Button variant="secondary" onClick={() => setEditing(true)}>
              <Pencil size={32} aria-hidden="true" />
              Edit
            </Button>
          )}
        </div>
      </div>

      {transitions.length > 0 && (
        <section className="flex flex-col gap-4 rounded-lg border-2 border-border bg-surface p-6">
          <h2 className="text-base font-bold text-ink-strong">What would you like to do?</h2>
          <TicketStatusActions
            ticketId={ticket.id}
            transitions={transitions}
            /*
              The same slugs mean different things to the person who reported the
              fault: closing their own ticket is confirming it is fixed, and
              reopening it is saying it is not.
            */
            labels={{
              closed: 'Confirm this is fixed',
              open: 'It is still not fixed — reopen',
              cancelled: 'Withdraw this report',
            }}
            hint={
              meta.reopen_window_days !== undefined
                ? `A resolved ticket can be reopened for ${meta.reopen_window_days} days after it closes. After that, report it again and the IT team will link the two.`
                : undefined
            }
          />
        </section>
      )}

      <Tabs tabs={tabs} value={tab} onChange={setTab} />

      {tab === 'comments' && (
        <TicketComments
          ticketId={ticket.id}
          canComment={can?.comment ?? false}
          canCommentInternal={can?.comment_internal ?? false}
        />
      )}

      {tab === 'files' && (
        <TicketAttachments ticketId={ticket.id} canManage={can?.attach ?? false} />
      )}

      <TicketEditDrawer open={editing} onClose={() => setEditing(false)} ticket={ticket} />
    </div>
  )
}

/**
 * Someone else's ticket, seen from the community.
 *
 * Deliberately spare: a title, an excerpt, where it is and how it is going. The
 * upvote and the comment box are the only things to do here, and they are the
 * whole reason this view exists — recognising that a fault is already reported
 * and adding to it rather than filing a second ticket.
 */
function CommunityView({
  ticket,
  canComment,
  onBack,
}: {
  ticket: TicketCard
  canComment: boolean
  onBack: () => void
}) {
  return (
    <div className="flex flex-col gap-8">
      <BackLink onClick={onBack} />

      <Alert tone="info" title="Someone else reported this">
        You are seeing the shared summary of another person's report. The full description,
        photographs and the IT team's notes stay with the people working on it.
      </Alert>

      <div className="flex flex-wrap items-start justify-between gap-6">
        <div className="flex min-w-0 flex-1 flex-col gap-4">
          <div className="flex flex-wrap items-center gap-3">
            <span className="text-sm font-bold tnum text-ink-strong">{ticket.ticket_number}</span>
            <TicketStatusBadge status={ticket.status} />
            <TicketPriorityBadge priority={ticket.priority} />
            {ticket.category.label && <Badge tone="outline">{ticket.category.label}</Badge>}
          </div>

          <h1 className="text-2xl font-bold text-ink-strong">{ticket.title}</h1>

          <p className="measure text-base text-ink">{ticket.excerpt}</p>

          <dl className="flex flex-wrap gap-x-8 gap-y-3 text-sm text-muted">
            <div className="flex gap-2">
              <dt>Reported by</dt>
              <dd className="text-ink">{ticket.reporter.name ?? 'Someone'}</dd>
            </div>
            <div className="flex gap-2">
              <dt>Reported</dt>
              <dd className="text-ink">{formatRelative(ticket.created_at)}</dd>
            </div>
            {ticket.pc_unit && (
              <div className="flex gap-2">
                <dt>Affected PC</dt>
                <dd className="text-ink">
                  {ticket.pc_unit.label} ({ticket.pc_unit.identifier})
                </dd>
              </div>
            )}
            {ticket.location?.room && (
              <div className="flex gap-2">
                <dt>Location</dt>
                <dd className="text-ink">
                  {ticket.location.room}
                  {ticket.location.building ? `, ${ticket.location.building}` : ''}
                </dd>
              </div>
            )}
          </dl>
        </div>

        <VoteButton
          ticketId={ticket.id}
          upvoteCount={ticket.upvote_count}
          hasVoted={ticket.has_voted}
          disabled={!ticket.status.is_open}
        />
      </div>

      <section>
        <h2 className="text-base font-bold text-ink-strong">Comments</h2>
        <div className="mt-4">
          {/*
            `comment_internal` is false without asking: an internal note never
            reaches this response, and the API refuses the field for a requester
            outright rather than quietly publishing the note.
          */}
          <TicketComments
            ticketId={ticket.id}
            canComment={canComment && ticket.status.is_open}
            canCommentInternal={false}
          />
        </div>
      </section>
    </div>
  )
}

function BackLink({ onClick }: { onClick: () => void }) {
  return (
    <div>
      <Button variant="ghost" size="sm" onClick={onClick}>
        <ArrowLeft size={26} aria-hidden="true" />
        All tickets
      </Button>
    </div>
  )
}

function isNotFound(error: unknown): boolean {
  return (
    typeof error === 'object' &&
    error !== null &&
    'response' in error &&
    typeof (error as { response?: { status?: number } }).response?.status === 'number' &&
    [403, 404].includes((error as { response: { status: number } }).response.status)
  )
}

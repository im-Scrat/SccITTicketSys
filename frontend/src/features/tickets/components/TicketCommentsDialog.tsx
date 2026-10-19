import { ArrowRight } from 'lucide-react'
import { Button, ButtonLink, Modal } from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import type { TicketCard } from '../types'
import { TicketComments } from './TicketComments'

interface TicketCommentsDialogProps {
  ticket: TicketCard
  open: boolean
  onClose: () => void
  /** Where "Open full ticket" goes — the same page the card itself opens. */
  href: string
}

/**
 * A ticket's conversation, opened from its card without leaving the list.
 *
 * The feed exists so a teacher can find "is this already known?" and add their
 * weight to it; reading what colleagues and the IT team have said, and adding a
 * line, is part of the same errand. A dialog keeps their place in the feed.
 *
 * Commenting is offered whatever the ticket's status (owner decision,
 * 2026-10-14): "it is still broken" belongs on a resolved ticket too, and the
 * server has never gated comments on status. Internal notes are not offered
 * here — staff write those from the full ticket, where the internal context is.
 */
export function TicketCommentsDialog({ ticket, open, onClose, href }: TicketCommentsDialogProps) {
  const { hasPermission } = useAuth()

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="lg"
      title="Comments"
      description={
        <>
          <span className="tnum font-semibold text-ink">{ticket.ticket_number}</span> ·{' '}
          {ticket.title}
        </>
      }
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Close
          </Button>
          <ButtonLink
            to={href}
            variant="secondary"
            rightIcon={<ArrowRight size={20} aria-hidden="true" />}
          >
            Open full ticket
          </ButtonLink>
        </>
      }
    >
      <TicketComments
        ticketId={ticket.id}
        canComment={hasPermission('tickets.comment')}
        canCommentInternal={false}
      />
    </Modal>
  )
}

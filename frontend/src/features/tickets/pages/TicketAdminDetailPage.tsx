import { ArrowLeft } from 'lucide-react'
import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Alert, Button, PageLoader, Tabs } from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { AdminTicketActions } from '../components/AdminTicketActions'
import { TicketAttachments } from '../components/TicketAttachments'
import { TicketComments } from '../components/TicketComments'
import { TicketEditDrawer } from '../components/TicketEditDrawer'
import { TicketStatusActions } from '../components/TicketStatusActions'
import { TicketSummary } from '../components/TicketSummary'
import { useAdminTicket, useTicketOptions } from '../hooks/queries'

/**
 * One ticket, as the Administrator managing it (SRS FR-TKT-013, FR-ASN-001/002).
 *
 * The full record, including everything the other two views cannot show:
 * contact details, the assigned technician, SLA deadlines and posture, the
 * duplicate chain, and the AI snapshot — the last rendered as "not analysed"
 * rather than faked, because the analysis job is a later phase and an
 * administrator should be able to tell a missing feature from a silent failure.
 *
 * Assignment, priority and the duplicate link each go through their own audited
 * endpoint rather than an edit form; see {@link AdminTicketActions}.
 */
export default function TicketAdminDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { hasPermission } = useAuth()

  const { data, isLoading, isError } = useAdminTicket(id)
  const options = useTicketOptions()

  const [tab, setTab] = useState('details')
  const [editing, setEditing] = useState(false)

  useDocumentMeta({ title: data ? data.data.ticket_number : 'Ticket' })

  if (isLoading) return <PageLoader />

  if (isError || !data) {
    return (
      <div className="flex flex-col gap-8">
        <BackLink onClick={() => navigate('/app/tickets/manage')} />
        <Alert tone="error" title="This ticket could not be opened">
          It may have been removed. Refresh the page to try again.
        </Alert>
      </div>
    )
  }

  const ticket = data.data
  const meta = data.meta ?? {}

  const tabs = [
    { value: 'details', label: 'Details' },
    { value: 'comments', label: 'Comments', count: ticket.comment_count },
    { value: 'files', label: 'Photos & files', count: ticket.attachment_count },
  ]

  return (
    <div className="flex flex-col gap-8">
      <BackLink onClick={() => navigate('/app/tickets/manage')} />

      {ticket.archived && (
        <Alert tone="warning" title="This ticket is archived">
          It is kept for the record and does not appear in the working directory.
        </Alert>
      )}

      <TicketSummary ticket={ticket} sla={meta.sla ?? null} linkEquipment />

      <section className="flex flex-col gap-5 rounded-lg border-2 border-border bg-surface p-6">
        <h2 className="text-base font-bold text-ink-strong">Manage this ticket</h2>

        <AdminTicketActions
          ticket={ticket}
          options={options.data}
          canAssign={hasPermission('tickets.assign')}
        />

        <div className="flex flex-wrap gap-3">
          <Button variant="secondary" onClick={() => setEditing(true)}>
            Edit the report
          </Button>
        </div>

        {(meta.transitions?.length ?? 0) > 0 && (
          <div className="border-t border-border pt-5">
            <h3 className="text-sm font-semibold text-ink">Move this ticket</h3>
            <div className="mt-3">
              <TicketStatusActions
                ticketId={ticket.id}
                transitions={meta.transitions ?? []}
                hint={
                  meta.reopen_window_days !== undefined
                    ? `A resolved ticket closes itself after the configured window; you can close or reopen one at any point regardless — administrator intervention is never blocked by the timer.`
                    : undefined
                }
              />
            </div>
          </div>
        )}
      </section>

      <Tabs tabs={tabs} value={tab} onChange={setTab} />

      {tab === 'comments' && <TicketComments ticketId={ticket.id} canComment canCommentInternal />}

      {tab === 'files' && <TicketAttachments ticketId={ticket.id} canManage />}

      <TicketEditDrawer open={editing} onClose={() => setEditing(false)} ticket={ticket} />
    </div>
  )
}

function BackLink({ onClick }: { onClick: () => void }) {
  return (
    <div>
      <Button variant="ghost" size="sm" onClick={onClick}>
        <ArrowLeft size={26} aria-hidden="true" />
        Ticket management
      </Button>
    </div>
  )
}

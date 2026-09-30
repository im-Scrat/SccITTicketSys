import { ArrowLeft } from 'lucide-react'
import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { Alert, PageLoader, Button, Tabs } from '@/components/ui'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { AssignmentActions } from '../components/AssignmentActions'
import { TicketAttachments } from '../components/TicketAttachments'
import { TicketComments } from '../components/TicketComments'
import { TicketRepairRecord } from '../components/TicketRepairRecord'
import { TicketStatusActions } from '../components/TicketStatusActions'
import { TicketSummary } from '../components/TicketSummary'
import { useAssignedTicket } from '../hooks/queries'

/**
 * One assigned ticket, as the technician working it (SRS FR-ASN-004/005).
 *
 * Everything needed to do the job is on this page: the fault as reported, the
 * evidence, the machine and where it is. The equipment context is the reason the
 * PC field matters — it is a label here, not a link into the register, because a
 * technician holds no `assets.view` and the narrow lookup is all they ever get
 * (SDD DD-38).
 *
 * **A read-only ticket keeps its page.** When `meta.read_only` is set, the work
 * is finished or handed on: the record stays fully readable for reference, and
 * every write affordance is gone rather than present-and-failing. The server
 * decides that flag from `TicketVisibility::canWork`, the same check that
 * authorizes the writes.
 */
export default function AssignedTicketDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { data, isLoading, isError, error } = useAssignedTicket(id)
  const [tab, setTab] = useState('details')

  useDocumentMeta({ title: data ? data.data.ticket_number : 'Assigned ticket' })

  if (isLoading) return <PageLoader />

  if (isError || !data) {
    return (
      <div className="flex flex-col gap-8">
        <BackLink onClick={() => navigate('/app/tickets/assigned')} />
        <Alert tone="error" title="This ticket could not be opened">
          {isForbidden(error)
            ? 'It is not one of your assignments. Only tickets assigned to you are available here.'
            : 'Refresh the page to try again.'}
        </Alert>
      </div>
    )
  }

  const ticket = data.data
  const meta = data.meta ?? {}
  const assignment = meta.assignment ?? null
  const readOnly = meta.read_only ?? false

  const tabs = [
    { value: 'details', label: 'Details' },
    { value: 'comments', label: 'Comments', count: ticket.comment_count },
    { value: 'files', label: 'Photos & files', count: ticket.attachment_count },
  ]

  return (
    <div className="flex flex-col gap-8">
      <BackLink onClick={() => navigate('/app/tickets/assigned')} />

      {readOnly && (
        <Alert tone="info" title="This is finished work">
          You are no longer assigned to this ticket. It stays here so you can refer back to what was
          done — nothing on it can be changed.
        </Alert>
      )}

      <TicketSummary ticket={ticket} sla={meta.sla ?? null} />

      {assignment && !readOnly && (
        <AssignmentActions ticketId={ticket.id} assignment={assignment} />
      )}

      {meta.repair && (
        <TicketRepairRecord ticket={ticket} repair={meta.repair} readOnly={readOnly} />
      )}

      {!readOnly && (meta.transitions?.length ?? 0) > 0 && (
        <section className="flex flex-col gap-4 rounded-lg border-2 border-border bg-surface p-6">
          <h2 className="text-base font-bold text-ink-strong">Move this ticket</h2>
          <p className="measure text-sm text-muted">
            Use the assignment buttons above for the ordinary flow. These are here for the cases
            that do not fit it.
          </p>
          <TicketStatusActions ticketId={ticket.id} transitions={meta.transitions ?? []} />
        </section>
      )}

      <Tabs tabs={tabs} value={tab} onChange={setTab} />

      {tab === 'comments' && (
        <TicketComments
          ticketId={ticket.id}
          canComment={!readOnly}
          /*
            A technician working the ticket can post a note the reporter never
            sees — diagnosis, parts, anything that would be unhelpful or alarming
            read out of context. The server decides whether the field is accepted;
            it refuses it outright rather than quietly publishing the note.
          */
          canCommentInternal={!readOnly}
          readOnly={readOnly}
        />
      )}

      {tab === 'files' && <TicketAttachments ticketId={ticket.id} canManage={!readOnly} />}
    </div>
  )
}

function BackLink({ onClick }: { onClick: () => void }) {
  return (
    <div>
      <Button variant="ghost" size="sm" onClick={onClick}>
        <ArrowLeft size={26} aria-hidden="true" />
        My work
      </Button>
    </div>
  )
}

function isForbidden(error: unknown): boolean {
  return (
    typeof error === 'object' &&
    error !== null &&
    'response' in error &&
    [403, 404].includes((error as { response?: { status?: number } }).response?.status ?? 0)
  )
}

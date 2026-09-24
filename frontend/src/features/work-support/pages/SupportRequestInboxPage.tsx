import { Archive, CalendarClock, Inbox, MessageSquare, XCircle } from 'lucide-react'
import { type FormEvent, useState } from 'react'
import {
  Alert,
  Button,
  EmptyState,
  Field,
  Input,
  Modal,
  SectionHeading,
  Skeleton,
  Surface,
  Tabs,
  Textarea,
} from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { SupportRequestCard } from '../components/SupportRequestCard'
import {
  useApproveSupportRequest,
  useCloseSupportRequest,
  useDeclineSupportRequest,
  useRequestClarification,
} from '../hooks/mutations'
import { useSupportRequestInbox } from '../hooks/queries'
import type { SupportRequest, WorkSupportStatus } from '../types'

const FILTERS = [
  { value: 'pending', label: 'Needs a decision' },
  { value: 'approved', label: 'Approved' },
  { value: 'declined', label: 'Declined' },
  { value: 'closed', label: 'Closed' },
  { value: 'all', label: 'All' },
]

type Decision = 'approve' | 'clarify' | 'decline'

/**
 * Receiving and deciding technician requests (SRS FR-WSR-005/006/007/008/010).
 *
 * ── An inbox, not a notification list ──────────────────────────────────────
 *
 * FR-WSR-005 is explicit that this is "not a bare notification": every card
 * carries the technician, the machine, the job, the items, the explanation and
 * the evidence count, so a decision can be made from the list without opening
 * anything. That is why there is no separate detail *page* — the card already
 * holds what FR-WSR-005 enumerates, and a second surface would be a second
 * place to forget a field.
 *
 * ── Three buttons, three dialogs, never a status dropdown ──────────────────
 *
 * The API has no endpoint that sets a status, so this has no control that would
 * imply one. Each decision opens a small dialog for exactly the evidence the
 * server requires — a date to approve, a subject to discuss, an explanation to
 * decline — and the decline dialog cannot be submitted empty, because a decline
 * without a reason is refused three layers deep and it would be dishonest to
 * let someone type nothing and find that out afterwards.
 */
export default function SupportRequestInboxPage() {
  useDocumentMeta({ title: 'Support requests' })

  const [filter, setFilter] = useState<string>('pending')
  const [error, setError] = useState<string | null>(null)
  const [dialog, setDialog] = useState<{ request: SupportRequest; decision: Decision } | null>(null)

  const status = filter === 'all' ? undefined : (filter as WorkSupportStatus | 'pending')

  const query = useSupportRequestInbox(status)
  const close = useCloseSupportRequest()

  const requests = query.data?.data ?? []

  async function run(action: () => Promise<unknown>) {
    setError(null)
    try {
      await action()
      setDialog(null)
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <SectionHeading
        as="h1"
        title="Support requests"
        lead="Technicians asking for the parts, materials and decisions their jobs need."
      />

      <Tabs tabs={FILTERS} value={filter} onChange={setFilter} />

      {error && (
        <Alert tone="error" title="That decision was not recorded">
          {error}
        </Alert>
      )}

      {query.isPending ? (
        <div className="flex flex-col gap-4">
          {[0, 1].map((n) => (
            <Surface key={n} className="p-6">
              <Skeleton className="h-5 w-56" />
              <Skeleton className="mt-3 h-4 w-full max-w-xl" />
              <Skeleton className="mt-2 h-4 w-full max-w-lg" />
            </Surface>
          ))}
        </div>
      ) : requests.length === 0 ? (
        <EmptyState
          icon={<Inbox className="size-6" />}
          title={filter === 'pending' ? 'Nothing waiting on you' : 'Nothing here'}
          description={
            filter === 'pending'
              ? 'Every technician request has been answered. New ones appear here as soon as they are raised.'
              : 'No requests are in this state.'
          }
        />
      ) : (
        <div className="flex flex-col gap-4">
          {requests.map((request) => (
            <SupportRequestCard
              key={request.id}
              request={request}
              showTechnician
              actions={
                request.is_undecided ? (
                  <>
                    <Button
                      type="button"
                      size="sm"
                      leftIcon={<CalendarClock className="size-4" />}
                      onClick={() => setDialog({ request, decision: 'approve' })}
                    >
                      Approve &amp; reschedule
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant="secondary"
                      leftIcon={<MessageSquare className="size-4" />}
                      onClick={() => setDialog({ request, decision: 'clarify' })}
                    >
                      Discuss face to face
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant="danger"
                      leftIcon={<XCircle className="size-4" />}
                      onClick={() => setDialog({ request, decision: 'decline' })}
                    >
                      Decline
                    </Button>
                  </>
                ) : request.is_terminal ? null : (
                  <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    leftIcon={<Archive className="size-4" />}
                    loading={close.isPending}
                    onClick={() => void run(() => close.mutateAsync(request.id))}
                  >
                    Close
                  </Button>
                )
              }
            />
          ))}
        </div>
      )}

      {dialog !== null && (
        <DecisionDialog
          request={dialog.request}
          decision={dialog.decision}
          onClose={() => setDialog(null)}
          onRun={run}
        />
      )}
    </div>
  )
}

function DecisionDialog({
  request,
  decision,
  onClose,
  onRun,
}: {
  request: SupportRequest
  decision: Decision
  onClose: () => void
  onRun: (action: () => Promise<unknown>) => Promise<void>
}) {
  const approve = useApproveSupportRequest()
  const clarify = useRequestClarification()
  const decline = useDeclineSupportRequest()

  const [date, setDate] = useState('')
  const [reason, setReason] = useState('')
  const [meetingAt, setMeetingAt] = useState('')

  const titles: Record<Decision, string> = {
    approve: 'Approve and reschedule',
    clarify: 'Ask for a face-to-face discussion',
    decline: 'Decline this request',
  }

  function submit(event: FormEvent) {
    event.preventDefault()

    if (decision === 'approve') {
      void onRun(() =>
        approve.mutateAsync({
          id: request.id,
          rescheduled_to: new Date(date).toISOString(),
          reschedule_reason: reason.trim() || undefined,
        }),
      )

      return
    }

    if (decision === 'clarify') {
      void onRun(() =>
        clarify.mutateAsync({
          id: request.id,
          clarification_reason: reason,
          proposed_meeting_at: meetingAt ? new Date(meetingAt).toISOString() : undefined,
        }),
      )

      return
    }

    void onRun(() => decline.mutateAsync({ id: request.id, reason }))
  }

  const pending = approve.isPending || clarify.isPending || decline.isPending
  const reasonRequired = decision !== 'approve'
  const canSubmit = decision === 'approve' ? date !== '' : reason.trim().length >= 5

  return (
    <Modal open onClose={onClose} title={titles[decision]}>
      <form className="flex flex-col gap-5" onSubmit={submit} noValidate>
        <p className="text-sm text-muted">
          {request.pc_unit?.unit_code} · asked by {request.technician?.name}
        </p>

        {decision === 'approve' && (
          <Field
            label="When can the work go ahead?"
            required
            hint="The linked maintenance job is rescheduled to this date, and the technician is asked to acknowledge it."
          >
            <Input
              type="datetime-local"
              value={date}
              onChange={(event) => setDate(event.target.value)}
            />
          </Field>
        )}

        <Field
          label={
            decision === 'approve'
              ? 'Note for the technician (optional)'
              : decision === 'clarify'
                ? 'What do you want to discuss?'
                : 'Why is this declined?'
          }
          required={reasonRequired}
          hint={
            decision === 'decline'
              ? 'The technician reads this. A decline with no reason is refused by the server.'
              : undefined
          }
        >
          <Textarea
            rows={4}
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            placeholder={
              decision === 'approve'
                ? 'The part arrives on Monday.'
                : decision === 'clarify'
                  ? 'Come and walk me through why the whole unit needs replacing.'
                  : 'No budget this term — raise it again in the next procurement round.'
            }
          />
        </Field>

        {decision === 'clarify' && (
          <Field label="Suggested time (optional)" hint="A note, not a calendar invitation.">
            <Input
              type="datetime-local"
              value={meetingAt}
              onChange={(event) => setMeetingAt(event.target.value)}
            />
          </Field>
        )}

        <div className="flex flex-wrap gap-3">
          <Button
            type="submit"
            variant={decision === 'decline' ? 'danger' : 'primary'}
            loading={pending}
            disabled={!canSubmit}
          >
            {decision === 'approve' ? 'Approve' : decision === 'clarify' ? 'Send' : 'Decline'}
          </Button>
          <Button type="button" variant="ghost" onClick={onClose}>
            Cancel
          </Button>
        </div>
      </form>
    </Modal>
  )
}

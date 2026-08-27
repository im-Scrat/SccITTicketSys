import { useState } from 'react'
import { Alert, Button, Field, Modal, Textarea } from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useChangeTicketStatus } from '../hooks/mutations'
import type { TicketTransition } from '../types'

/**
 * Action wording, keyed on the **target** status.
 *
 * A raw status name is a poor button: "Closed" does not say what pressing it
 * does, and the same move means different things to different people — a
 * reporter moving a ticket to Closed is confirming their problem is fixed, while
 * an administrator doing it is closing the record. The pages pass an override
 * map for the handful of moves where the actor changes the meaning.
 */
const DEFAULT_LABELS: Record<string, string> = {
  open: 'Return to the queue',
  assigned: 'Send back to assigned',
  'in-progress': 'Start work',
  'on-hold': 'Put on hold',
  resolved: 'Mark as resolved',
  closed: 'Close ticket',
  cancelled: 'Cancel ticket',
}

interface TicketStatusActionsProps {
  ticketId: string
  transitions: TicketTransition[]
  labels?: Record<string, string>
  /** Explanatory line under the buttons, e.g. the reopen window. */
  hint?: string
  disabled?: boolean
}

/**
 * The lifecycle moves this caller may actually make (SRS FR-TKT-005).
 *
 * The buttons come from `meta.transitions`, which the server computes from
 * `TicketLifecycle` for *this actor on this ticket* — the client never derives
 * them. That is the whole point: the same transition map that authorizes the
 * write decides what is offered, so a button can never exist for a move the API
 * would refuse, and a permitted move can never be missing because the UI forgot
 * a case.
 *
 * Every move opens a confirmation carrying an optional remark. The remark is
 * written to the ticket's status history, which is what makes a lifecycle
 * readable afterwards — "why was this put on hold for a week" is a question the
 * history should be able to answer.
 */
export function TicketStatusActions({
  ticketId,
  transitions,
  labels,
  hint,
  disabled = false,
}: TicketStatusActionsProps) {
  const change = useChangeTicketStatus(ticketId)
  const [target, setTarget] = useState<TicketTransition | null>(null)
  const [remarks, setRemarks] = useState('')
  const [error, setError] = useState<string | null>(null)

  if (transitions.length === 0) return null

  const labelFor = (transition: TicketTransition) =>
    labels?.[transition.value] ?? DEFAULT_LABELS[transition.value] ?? `Move to ${transition.label}`

  const close = () => {
    setTarget(null)
    setRemarks('')
    setError(null)
  }

  const confirm = async () => {
    if (!target) return
    setError(null)
    try {
      await change.mutateAsync({ status: target.value, remarks: remarks.trim() || undefined })
      close()
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap gap-3">
        {transitions.map((transition) => (
          <Button
            key={transition.value}
            variant={transition.value === 'cancelled' ? 'danger' : 'secondary'}
            disabled={disabled}
            onClick={() => setTarget(transition)}
          >
            {labelFor(transition)}
          </Button>
        ))}
      </div>

      {hint && <p className="text-sm text-muted">{hint}</p>}

      <Modal
        open={target !== null}
        onClose={close}
        title={target ? labelFor(target) : ''}
        description={
          target
            ? `This moves the ticket to ${target.label}${target.terminal ? ', which ends it' : ''}.`
            : undefined
        }
        footer={
          <>
            <Button variant="ghost" size="sm" onClick={close} disabled={change.isPending}>
              Cancel
            </Button>
            <Button
              size="sm"
              variant={target?.value === 'cancelled' ? 'danger' : 'primary'}
              loading={change.isPending}
              onClick={confirm}
            >
              {target ? labelFor(target) : 'Confirm'}
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-4">
          {error && <Alert tone="error">{error}</Alert>}
          <Field
            label="Remark (optional)"
            hint="Recorded on the ticket's history — this is what explains the change later."
          >
            <Textarea
              value={remarks}
              rows={4}
              maxLength={2000}
              onChange={(event) => setRemarks(event.target.value)}
            />
          </Field>
        </div>
      </Modal>
    </div>
  )
}

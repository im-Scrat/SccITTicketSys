import { useState } from 'react'
import { Alert, Button, Field, Modal, Textarea } from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { formatDateTime } from '@/lib/datetime'
import type { AssignmentAction } from '../api/ticketsApi'
import { useRespondToAssignment } from '../hooks/mutations'
import type { AssignmentMeta } from '../types'

interface ActionSpec {
  action: AssignmentAction
  label: string
  description: string
  /** A decline is the one response that must say why. */
  requiresReason?: boolean
  tone?: 'primary' | 'secondary' | 'danger'
}

const ACTIONS: ActionSpec[] = [
  {
    action: 'accept',
    label: 'Accept this job',
    description: 'You are taking this on. The reporter is told their ticket has been picked up.',
    tone: 'primary',
  },
  {
    action: 'start',
    label: 'Start work',
    description: 'Moves the ticket to In Progress so everyone can see it is being worked on.',
    tone: 'primary',
  },
  {
    action: 'hold',
    label: 'Put on hold',
    description:
      'For work that cannot continue yet — waiting on a part, on access to a room, or on the reporter.',
    tone: 'secondary',
  },
  {
    action: 'complete',
    label: 'Mark the work complete',
    description:
      'Moves the ticket to Resolved and asks the reporter to confirm the problem is actually fixed.',
    tone: 'primary',
  },
  {
    action: 'decline',
    label: 'Decline this job',
    description:
      'Returns the ticket to the administrator to be reassigned. Your reason is what they place it from.',
    requiresReason: true,
    tone: 'danger',
  },
]

interface AssignmentActionsProps {
  ticketId: string
  assignment: AssignmentMeta
}

/**
 * A technician's response to being given work (SRS FR-ASN-004/005).
 *
 * Which buttons exist is decided by `meta.assignment.can`, computed by the
 * server from `TechnicianAssignmentPolicy` — the client does not infer them from
 * the assignment status. An assignment that has been reassigned away, declined,
 * or completed therefore offers nothing, without this component needing to know
 * the rule.
 *
 * Declining requires a reason and says so before the button is pressed. A
 * decline sends the ticket back to the queue, and the reason is the only thing
 * telling the administrator how to place it better — "not mine" costs someone
 * else the same discovery.
 */
export function AssignmentActions({ ticketId, assignment }: AssignmentActionsProps) {
  const respond = useRespondToAssignment(ticketId)
  const [active, setActive] = useState<ActionSpec | null>(null)
  const [text, setText] = useState('')
  const [error, setError] = useState<string | null>(null)

  const available = ACTIONS.filter((spec) => assignment.can[spec.action])

  const close = () => {
    setActive(null)
    setText('')
    setError(null)
  }

  const confirm = async () => {
    if (!active) return
    const trimmed = text.trim()

    if (active.requiresReason && trimmed.length < 5) {
      setError('Say why, in at least 5 characters — this is what the administrator reassigns from.')
      return
    }

    setError(null)
    try {
      await respond.mutateAsync({
        action: active.action,
        reason: active.requiresReason ? trimmed : undefined,
        remarks: active.requiresReason ? undefined : trimmed || undefined,
      })
      close()
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  return (
    <section className="flex flex-col gap-5 rounded-lg border-2 border-border bg-surface p-6">
      <div>
        <h2 className="text-base font-bold text-ink-strong">Your assignment</h2>
        <dl className="mt-3 flex flex-wrap gap-x-8 gap-y-2 text-sm text-muted">
          <div className="flex gap-2">
            <dt>Assigned</dt>
            <dd className="text-ink">{formatDateTime(assignment.assigned_at)}</dd>
          </div>
          {assignment.accepted_at && (
            <div className="flex gap-2">
              <dt>Accepted</dt>
              <dd className="text-ink">{formatDateTime(assignment.accepted_at)}</dd>
            </div>
          )}
          {assignment.started_at && (
            <div className="flex gap-2">
              <dt>Started</dt>
              <dd className="text-ink">{formatDateTime(assignment.started_at)}</dd>
            </div>
          )}
          {assignment.completed_at && (
            <div className="flex gap-2">
              <dt>Completed</dt>
              <dd className="text-ink">{formatDateTime(assignment.completed_at)}</dd>
            </div>
          )}
        </dl>
      </div>

      {available.length === 0 ? (
        <p className="measure text-base text-muted">
          There is nothing left to do on this assignment. It stays readable so you can refer back to
          the work.
        </p>
      ) : (
        <div className="flex flex-wrap gap-3">
          {available.map((spec) => (
            <Button
              key={spec.action}
              variant={
                spec.tone === 'danger'
                  ? 'danger'
                  : spec.tone === 'primary'
                    ? 'primary'
                    : 'secondary'
              }
              onClick={() => setActive(spec)}
            >
              {spec.label}
            </Button>
          ))}
        </div>
      )}

      <Modal
        open={active !== null}
        onClose={close}
        title={active?.label ?? ''}
        description={active?.description}
        footer={
          <>
            <Button variant="ghost" size="sm" onClick={close} disabled={respond.isPending}>
              Cancel
            </Button>
            <Button
              size="sm"
              variant={active?.tone === 'danger' ? 'danger' : 'primary'}
              loading={respond.isPending}
              onClick={confirm}
            >
              {active?.label ?? 'Confirm'}
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-4">
          {error && <Alert tone="error">{error}</Alert>}
          <Field
            label={active?.requiresReason ? 'Why are you declining?' : 'Notes (optional)'}
            required={active?.requiresReason}
            hint={
              active?.requiresReason
                ? 'The administrator sees this when reassigning.'
                : 'Recorded on the ticket history and on your assignment.'
            }
          >
            <Textarea
              value={text}
              rows={4}
              maxLength={2000}
              onChange={(event) => setText(event.target.value)}
            />
          </Field>
        </div>
      </Modal>
    </section>
  )
}

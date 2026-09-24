import { useState } from 'react'
import { Alert, Button, Field, Modal, Textarea } from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useChangeMaintenanceStatus } from '../hooks/mutations'
import type { MaintenanceDetail, TransitionOption } from '../types'

/**
 * The lifecycle actions for one record (SRS FR-MNT-003/004/008/010).
 *
 * **Every button here came from the server.** `available_transitions` is
 * computed by `MaintenanceLifecycle` — the same code that would refuse the
 * request — so the client never guesses which moves are legal, and a button the
 * API would reject cannot appear.
 *
 * A blocked completion is rendered **disabled with its reasons shown**, not
 * hidden. A technician who cannot finish needs to know why; a silently missing
 * button teaches them nothing and sends them to look for a bug.
 */
export function MaintenanceStatusActions({ record }: { record: MaintenanceDetail }) {
  const change = useChangeMaintenanceStatus(record.id)

  const [pending, setPending] = useState<TransitionOption | null>(null)
  const [resolution, setResolution] = useState(record.resolution ?? '')
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)

  if (record.available_transitions.length === 0) return null

  const completing = pending?.value === 'completed'
  const cancelling = pending?.value === 'cancelled'

  async function confirm() {
    if (!pending) return
    setError(null)
    try {
      await change.mutateAsync({
        status: pending.value,
        resolution: completing ? resolution : undefined,
        reason: cancelling ? reason : undefined,
      })
      setPending(null)
      setReason('')
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  return (
    <>
      <div className="flex flex-wrap gap-3">
        {record.available_transitions.map((transition) => {
          const blocked = transition.blocked_by.length > 0

          return (
            <div key={transition.value} className="flex flex-col gap-1">
              <Button
                variant={transition.value === 'completed' ? 'primary' : 'secondary'}
                disabled={blocked}
                onClick={() => {
                  setError(null)
                  setPending(transition)
                }}
              >
                {actionLabel(transition.value, transition.label)}
              </Button>

              {blocked && (
                <ul className="max-w-72 list-disc pl-5 text-xs text-muted">
                  {transition.blocked_by.map((why) => (
                    <li key={why}>{why}</li>
                  ))}
                </ul>
              )}
            </div>
          )
        })}
      </div>

      <Modal
        open={pending !== null}
        onClose={() => setPending(null)}
        title={pending ? actionLabel(pending.value, pending.label) : ''}
        description={
          completing
            ? 'Record what was done. This becomes the account of the visit.'
            : cancelling
              ? 'Say why this visit is not going ahead.'
              : undefined
        }
        footer={
          <div className="flex justify-end gap-3">
            <Button variant="ghost" onClick={() => setPending(null)}>
              Cancel
            </Button>
            <Button
              variant={cancelling ? 'danger' : 'primary'}
              loading={change.isPending}
              onClick={() => void confirm()}
            >
              {pending ? actionLabel(pending.value, pending.label) : 'Confirm'}
            </Button>
          </div>
        }
      >
        <div className="flex flex-col gap-4">
          {error && <Alert tone="error">{error}</Alert>}

          {completing && (
            <Field label="What was done" required>
              <Textarea
                value={resolution}
                onChange={(event) => setResolution(event.target.value)}
                rows={5}
              />
            </Field>
          )}

          {cancelling && (
            <Field label="Reason" hint="Kept on the record's timeline.">
              <Textarea
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                rows={3}
              />
            </Field>
          )}

          {!completing && !cancelling && (
            <p className="text-sm text-muted">
              The record moves to <strong>{pending?.label}</strong>. The change is recorded on its
              timeline with your name.
            </p>
          )}
        </div>
      </Modal>
    </>
  )
}

/** Verbs, not state names — a button says what it does. */
function actionLabel(value: string, fallback: string): string {
  switch (value) {
    case 'in_progress':
      return 'Start work'
    case 'on_hold':
      return 'Put on hold'
    case 'completed':
      return 'Complete'
    case 'cancelled':
      return 'Cancel visit'
    default:
      return fallback
  }
}

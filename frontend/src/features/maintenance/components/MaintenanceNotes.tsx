import { NotebookPen } from 'lucide-react'
import { useState } from 'react'
import { Alert, Button, EmptyState, Field, Surface, Textarea } from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { formatDateTime } from '@/lib/datetime'
import { useAddMaintenanceNote } from '../hooks/mutations'
import type { MaintenanceNote } from '../types'

/**
 * The working log for one visit (SRS FR-MNT-005).
 *
 * **Append-only, and deliberately so.** A note is what the technician observed
 * at a moment during the job; editing or deleting one afterwards would make the
 * running account of the visit negotiable. Ticket comments allow moderation
 * because a comment is a conversation with a reporter — this is a logbook.
 *
 * There is no internal/public distinction either, and none is needed: no
 * non-staff role can reach a maintenance record at all, so the whole surface is
 * already the equivalent of an internal note.
 */
export function MaintenanceNotes({
  recordId,
  notes,
  canAdd,
}: {
  recordId: string
  notes: MaintenanceNote[]
  canAdd: boolean
}) {
  const add = useAddMaintenanceNote(recordId)
  const [body, setBody] = useState('')
  const [error, setError] = useState<string | null>(null)

  async function submit() {
    if (body.trim() === '') return
    setError(null)
    try {
      await add.mutateAsync(body.trim())
      setBody('')
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  return (
    <div className="flex flex-col gap-5">
      {canAdd && (
        <div className="flex flex-col gap-3">
          {error && <Alert tone="error">{error}</Alert>}

          <Field label="Add a note" hint="Kept on the record permanently — notes are not editable.">
            <Textarea
              value={body}
              onChange={(event) => setBody(event.target.value)}
              rows={3}
              placeholder="What you found, what you tried, what you are waiting on…"
            />
          </Field>

          <div>
            <Button
              variant="secondary"
              disabled={body.trim() === ''}
              loading={add.isPending}
              onClick={() => void submit()}
            >
              Add note
            </Button>
          </div>
        </div>
      )}

      {notes.length === 0 ? (
        <EmptyState
          icon={<NotebookPen className="size-6" aria-hidden="true" />}
          title="No notes yet"
          description="Notes record what happened between the diagnosis and the resolution."
        />
      ) : (
        <ol className="flex flex-col gap-3">
          {notes.map((note) => (
            <li key={note.id}>
              <Surface className="p-4">
                <p className="whitespace-pre-wrap text-sm text-ink">{note.body}</p>
                <p className="mt-2 text-xs text-muted">
                  {note.author?.name ?? 'Unknown'} · {formatDateTime(note.created_at)}
                </p>
              </Surface>
            </li>
          ))}
        </ol>
      )}
    </div>
  )
}

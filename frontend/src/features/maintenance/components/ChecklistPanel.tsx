import { CheckCircle2, Circle } from 'lucide-react'
import { useState } from 'react'
import { Alert, Badge, Checkbox, EmptyState, Surface } from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { formatDateTime } from '@/lib/datetime'
import { useToggleChecklistItem } from '../hooks/mutations'
import type { ChecklistItem } from '../types'

/**
 * The checklist issued with a maintenance record (SRS FR-MNT-004).
 *
 * **Required items are marked, and their state is the completion gate.** The
 * server refuses to complete a visit while one is outstanding, so this panel
 * says which they are up front rather than letting a technician discover the
 * rule at the moment they try to finish.
 *
 * `is_required` here is the *instance's* flag, copied from the template when the
 * record was opened — so editing or deleting the template afterwards cannot
 * change what this particular visit has to satisfy.
 */
export function ChecklistPanel({
  recordId,
  items,
  canEdit,
}: {
  recordId: string
  items: ChecklistItem[]
  canEdit: boolean
}) {
  const toggle = useToggleChecklistItem(recordId)
  const [error, setError] = useState<string | null>(null)
  const [pending, setPending] = useState<number | null>(null)

  const required = items.filter((item) => item.is_required)
  const outstanding = required.filter((item) => !item.is_completed)
  const completed = items.filter((item) => item.is_completed).length

  async function onToggle(item: ChecklistItem, next: boolean) {
    setError(null)
    setPending(item.id)
    try {
      await toggle.mutateAsync({ itemId: item.id, isCompleted: next })
    } catch (caught) {
      setError(getErrorMessage(caught))
    } finally {
      setPending(null)
    }
  }

  if (items.length === 0) {
    return (
      <EmptyState
        icon={<CheckCircle2 className="size-6" aria-hidden="true" />}
        title="No checklist"
        description="This maintenance type does not issue one. Nothing here blocks completion."
      />
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-muted">
          <span className="font-semibold text-ink-strong tnum">
            {completed} of {items.length}
          </span>{' '}
          complete
        </p>

        {outstanding.length > 0 ? (
          <Badge tone="warning">
            {outstanding.length} required item{outstanding.length === 1 ? '' : 's'} outstanding
          </Badge>
        ) : (
          required.length > 0 && <Badge tone="success">All required items done</Badge>
        )}
      </div>

      {error && <Alert tone="error">{error}</Alert>}

      <Surface className="divide-y divide-border p-0">
        {items.map((item) => (
          <div key={item.id} className="flex items-start gap-3 p-4">
            <Checkbox
              label={item.label}
              // The visible text sits beside the control with its own styling
              // (struck through once done), so the built-in label is hidden
              // visually but kept for assistive technology — a bare checkbox is
              // unusable with a screen reader.
              labelHidden
              checked={item.is_completed}
              disabled={!canEdit || pending === item.id}
              onChange={(event) => void onToggle(item, event.target.checked)}
            />

            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2">
                <span
                  className={
                    item.is_completed
                      ? 'text-sm text-muted line-through'
                      : 'text-sm text-ink-strong'
                  }
                  aria-hidden="true"
                >
                  {item.label}
                </span>
                {item.is_required && (
                  <Badge tone="outline" icon={<Circle className="size-2.5 fill-current" />}>
                    Required
                  </Badge>
                )}
              </div>

              {item.remarks && <p className="mt-1 text-xs text-muted">{item.remarks}</p>}

              {item.is_completed && item.completed_by && (
                <p className="mt-1 text-xs text-muted">
                  {item.completed_by.name} · {formatDateTime(item.completed_at)}
                </p>
              )}
            </div>
          </div>
        ))}
      </Surface>
    </div>
  )
}

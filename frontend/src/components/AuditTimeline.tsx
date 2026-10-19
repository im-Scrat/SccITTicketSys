import { History } from 'lucide-react'
import { EmptyState, Skeleton } from '@/components/ui'
import { formatDateTime } from '@/lib/datetime'
import type { ActivityEntry } from '@/types/activity'

interface AuditTimelineProps {
  entries: ActivityEntry[] | undefined
  isLoading: boolean
  /** Override the empty-state copy for the record type being shown. */
  emptyDescription?: string
}

/**
 * Chronological (newest-first) audit trail for one record: who did what, when,
 * and from where. Shared across modules — accounts, locations, and every later
 * domain read the same `activity_logs` shape, so the timeline looks and behaves
 * identically wherever it appears.
 */
export function AuditTimeline({ entries, isLoading, emptyDescription }: AuditTimelineProps) {
  if (isLoading) {
    return (
      <div className="flex flex-col gap-3">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-14" />
        ))}
      </div>
    )
  }

  if (!entries || entries.length === 0) {
    return (
      <EmptyState
        icon={<History size={22} />}
        title="No activity yet"
        description={
          emptyDescription ??
          'Administrative actions for this record will appear here as they happen.'
        }
      />
    )
  }

  return (
    <ol className="relative flex flex-col gap-0 border-l border-border pl-4">
      {entries.map((entry) => (
        <li key={entry.id} className="relative pb-5 last:pb-0">
          <span
            className="absolute -left-[1.3125rem] top-1 size-2 rounded-full bg-primary ring-2 ring-surface"
            aria-hidden="true"
          />
          <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
            <p className="text-sm font-medium text-ink-strong">{entry.label}</p>
            <time className="text-xs text-muted tnum">{formatDateTime(entry.created_at)}</time>
          </div>
          {entry.description && <p className="mt-0.5 text-sm text-muted">{entry.description}</p>}
          <p className="mt-0.5 text-xs text-muted">
            {entry.actor ? `by ${entry.actor.name}` : 'System'}
            {entry.ip_address ? ` · ${entry.ip_address}` : ''}
          </p>
        </li>
      ))}
    </ol>
  )
}

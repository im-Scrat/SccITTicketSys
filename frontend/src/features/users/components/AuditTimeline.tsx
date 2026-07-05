import { History } from 'lucide-react'
import { EmptyState, Skeleton } from '@/components/ui'
import { formatDateTime } from '../lib/format'
import type { ActivityEntry } from '../types'

interface AuditTimelineProps {
  entries: ActivityEntry[] | undefined
  isLoading: boolean
}

/**
 * Chronological (newest-first) audit trail for a user: registration, approval,
 * login events, status/role/permission changes and administrative actions. Each
 * row shows the action label, actor, and timestamp.
 */
export function AuditTimeline({ entries, isLoading }: AuditTimelineProps) {
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
        description="Registration, sign-ins, and administrative actions for this account will appear here."
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

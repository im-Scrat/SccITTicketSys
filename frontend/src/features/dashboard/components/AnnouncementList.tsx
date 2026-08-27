import { Pin } from 'lucide-react'
import { formatRelative } from '@/lib/datetime'
import type { AnnouncementRow } from '../types'

/**
 * Announcements for this reader's audience, pinned first (FR-NOT-010/011). Plain
 * prose in a quiet panel — an announcement is something to read, not a metric.
 */
export function AnnouncementList({ rows }: { rows: AnnouncementRow[] }) {
  return (
    <ul className="flex flex-col divide-y divide-border">
      {rows.map((row) => (
        <li key={row.id} className="py-2.5 first:pt-0 last:pb-0">
          <div className="flex items-baseline justify-between gap-3">
            <p className="flex items-center gap-1.5 text-sm font-medium text-ink-strong">
              {row.pinned && <Pin size={13} className="shrink-0 text-muted" aria-label="Pinned" />}
              {row.title}
            </p>
            <time className="shrink-0 text-xs text-muted tnum">{formatRelative(row.at)}</time>
          </div>
          <p className="mt-1 max-w-[65ch] text-sm leading-relaxed text-muted">{row.content}</p>
        </li>
      ))}
    </ul>
  )
}

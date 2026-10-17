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
            {/* The pin is an inline glyph after the title's last word. Leading it
                pushed a pinned title right of its unpinned neighbours; as a flex
                item it floated, detached, beside a wrapped title. */}
            <p className="text-sm font-medium text-ink-strong">
              {row.title}
              {row.pinned && (
                <Pin
                  size={13}
                  className="ml-1.5 inline align-[-1px] text-muted"
                  aria-label="Pinned"
                />
              )}
            </p>
            <time className="shrink-0 text-xs text-muted tnum">{formatRelative(row.at)}</time>
          </div>
          <p className="mt-1 max-w-[65ch] text-sm leading-relaxed text-muted">{row.content}</p>
        </li>
      ))}
    </ul>
  )
}

import { Pin } from 'lucide-react'
import { Link, useNavigate } from 'react-router-dom'
import { isInteractiveTarget } from '@/components/ui'
import { cn } from '@/lib/cn'
import { formatDateTime, formatRelative } from '@/lib/datetime'
import type { AnnouncementRow } from '../types'

/**
 * Announcements for this reader's audience, pinned first (FR-NOT-010/011). Plain
 * prose in a quiet panel — an announcement is something to read, not a metric.
 *
 * A row with an `href` opens the announcement: the title is a real link
 * (focusable, middle-click opens a tab) and the rest of the row is a larger
 * pointer target for the same place. A click that began on a nested link or
 * control is left to it. Rows without an `href` render as plain text, as before.
 */
export function AnnouncementList({ rows }: { rows: AnnouncementRow[] }) {
  const navigate = useNavigate()

  return (
    <ul className="flex flex-col divide-y divide-border">
      {rows.map((row) => {
        const href = row.href

        return (
          <li
            key={row.id}
            className={cn(
              'py-2.5 first:pt-0 last:pb-0',
              href && 'cursor-pointer rounded-sm hover:bg-surface-sunken',
            )}
            onClick={
              href
                ? (event) => {
                    if (!isInteractiveTarget(event)) navigate(href)
                  }
                : undefined
            }
          >
            <div className="flex items-baseline justify-between gap-3">
              {/* The pin is an inline glyph after the title's last word. Leading it
                  pushed a pinned title right of its unpinned neighbours; as a flex
                  item it floated, detached, beside a wrapped title. */}
              <p className="text-sm font-medium text-ink-strong">
                {href ? (
                  <Link to={href} className="rounded-sm hover:underline">
                    {row.title}
                  </Link>
                ) : (
                  row.title
                )}
                {row.pinned && (
                  <Pin
                    size={13}
                    className="ml-1.5 inline align-[-1px] text-muted"
                    aria-label="Pinned"
                  />
                )}
              </p>
              <time
                className="shrink-0 text-xs text-muted tnum"
                dateTime={row.at ?? undefined}
                title={formatDateTime(row.at)}
              >
                {formatRelative(row.at)}
              </time>
            </div>
            <p className="mt-1 max-w-[65ch] text-sm leading-relaxed text-muted">{row.content}</p>
          </li>
        )
      })}
    </ul>
  )
}

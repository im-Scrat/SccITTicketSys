import { Pin } from 'lucide-react'
import { Badge } from '@/components/ui/Badge'
import { Surface } from '@/components/ui/Surface'
import { formatDateTime, formatRelative } from '@/lib/datetime'
import type { Announcement } from '../types'

const AUDIENCE_LABELS: Record<string, string> = {
  all: 'Everyone',
  teachers: 'Teachers',
  technicians: 'Technicians',
  admins: 'Administrators',
}

/**
 * One announcement, as a reader sees it (FR-NOT-011).
 *
 * `content` is **plain text** (WP-2.7c decision D4) and is rendered as text — React
 * escapes it, and there is deliberately no HTML or Markdown path to sanitise.
 * `whitespace-pre-line` is what lets an administrator's paragraph breaks
 * survive without introducing markup.
 *
 * Pinned is never conveyed by the icon alone: the glyph is decorative and the
 * word "Pinned" carries the meaning, so the state survives grayscale and a
 * screen reader (NFR-ACC-004).
 */
export function AnnouncementCard({ announcement }: { announcement: Announcement }) {
  const audience = AUDIENCE_LABELS[announcement.audience] ?? announcement.audience

  return (
    <Surface as="article" className="p-5">
      <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
        <h2 className="min-w-0 flex-1 text-base font-semibold text-ink-strong">
          {announcement.title}
        </h2>

        <div className="flex shrink-0 items-center gap-2">
          {announcement.is_pinned && (
            <Badge tone="primary" icon={<Pin size={13} aria-hidden="true" />}>
              Pinned
            </Badge>
          )}
          <Badge tone="neutral">{audience}</Badge>
        </div>
      </div>

      <p className="mt-3 max-w-[65ch] whitespace-pre-line text-sm leading-relaxed text-ink">
        {announcement.content}
      </p>

      <p className="mt-4 text-xs text-muted">
        <time dateTime={announcement.created_at ?? undefined} title={formatDateTime(announcement.created_at)}>
          {formatRelative(announcement.created_at)}
        </time>
      </p>
    </Surface>
  )
}

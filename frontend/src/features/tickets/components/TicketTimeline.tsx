import {
  ArrowRightLeft,
  CircleDot,
  Flag,
  PlusCircle,
  ThumbsDown,
  ThumbsUp,
  UserCheck,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { Alert, Skeleton } from '@/components/ui'
import { formatDateTime, formatRelative, titleCase } from '@/lib/datetime'
import { useTicketTimeline } from '../hooks/queries'
import type { TimelineEntry } from '../types'

/** The sentence and glyph for one entry. Plain language, the reporter's terms. */
function describe(entry: TimelineEntry): { icon: LucideIcon; text: string } {
  switch (entry.kind) {
    case 'created':
      return { icon: PlusCircle, text: 'Reported' }
    case 'status':
      return {
        icon: ArrowRightLeft,
        text: entry.from
          ? `Status changed from ${entry.from} to ${entry.to ?? '—'}`
          : `Status set to ${entry.to ?? '—'}`,
      }
    case 'priority':
      return {
        icon: Flag,
        text: entry.from
          ? `Priority changed from ${entry.from} to ${entry.to ?? '—'}`
          : `Priority set to ${entry.to ?? '—'}`,
      }
    case 'outcome':
      return entry.outcome === 'not_fixed'
        ? { icon: ThumbsDown, text: 'Reported that the AI’s suggestions did not fix it' }
        : { icon: ThumbsUp, text: 'Reported that the AI’s suggestions fixed it' }
    case 'assignment':
      return {
        icon: UserCheck,
        text: entry.technician
          ? `${entry.reassigned ? 'Reassigned' : 'Assigned'} to ${entry.technician}`
          : entry.reassigned
            ? 'Reassigned to another technician'
            : 'Assigned to a technician',
      }
    case 'assignment_response':
      return {
        icon: CircleDot,
        text: `${entry.technician ?? 'The technician'} — ${titleCase((entry.assignment_status ?? 'updated').replace('_', ' '))}`,
      }
  }
}

/**
 * Phase 2.9 — a ticket's History tab: what happened to it, oldest first.
 *
 * Renders exactly what the server projected for this viewer. A requester's
 * history names "IT team" rather than a person and shows only their own
 * notes; there is nothing more in the response to reveal.
 */
export function TicketTimeline({ ticketId }: { ticketId: string }) {
  const timeline = useTicketTimeline(ticketId)

  if (timeline.isError) {
    return (
      <Alert tone="error">The history could not be loaded. Refresh the page to try again.</Alert>
    )
  }

  if (timeline.isLoading) {
    return (
      <div className="flex flex-col gap-3" aria-busy="true">
        <Skeleton className="h-12" />
        <Skeleton className="h-12" />
        <Skeleton className="h-12" />
      </div>
    )
  }

  const entries = timeline.data ?? []

  if (entries.length === 0) {
    return <p className="text-sm text-muted">Nothing has happened to this ticket yet.</p>
  }

  return (
    <ol className="flex flex-col">
      {entries.map((entry, index) => {
        const { icon: Icon, text } = describe(entry)
        const last = index === entries.length - 1

        return (
          <li key={`${entry.at ?? ''}-${index}`} className="relative flex gap-4 pb-6 last:pb-0">
            {/* The connecting rule: a 1px hairline, not an accent stripe. */}
            {!last && (
              <span
                aria-hidden="true"
                className="absolute top-9 bottom-0 left-[17px] w-px bg-border"
              />
            )}
            <span className="flex size-9 shrink-0 items-center justify-center rounded-full border border-border bg-surface text-muted">
              <Icon size={18} aria-hidden="true" />
            </span>
            <div className="min-w-0 pt-1.5">
              <p className="text-sm font-semibold text-ink-strong">{text}</p>
              <p className="mt-0.5 text-xs text-muted">
                {entry.actor}
                {entry.at && (
                  <>
                    {' · '}
                    <time dateTime={entry.at} title={formatDateTime(entry.at)}>
                      {formatRelative(entry.at)}
                    </time>
                  </>
                )}
              </p>
              {entry.note && (
                <p className="measure mt-2 whitespace-pre-line text-sm text-ink">{entry.note}</p>
              )}
            </div>
          </li>
        )
      })}
    </ol>
  )
}

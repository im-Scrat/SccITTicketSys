import { CircleCheck, TriangleAlert } from 'lucide-react'
import { useEffect, useId, useRef } from 'react'
import { cn } from '@/lib/cn'
import type { ReportOutcome } from '../reportOutcome'

interface ReportOutcomeNoticeProps {
  outcome: ReportOutcome
  /** The ticket on screen; the notice belongs to one ticket only. */
  ticketId: string
}

function photos(count: number): string {
  return `${count} ${count === 1 ? 'photo' : 'photos'}`
}

/**
 * "Your report was sent" — and, if any photo failed, which ones (SDD DD-80).
 *
 * Shown once, on the new ticket's page, from the router state the report form
 * navigated with. Three decisions:
 *
 *  - **Its heading takes focus when it appears.** The submit button the
 *    reporter pressed no longer exists, so focus would otherwise fall to the
 *    page body; and a live region that already holds its text when it mounts
 *    is not reliably read out. Moving focus to the heading (the
 *    NotificationsPage precedent) is what makes a screen reader say it.
 *  - **It is keyed to its ticket.** The ticket page is one component instance
 *    across `/app/tickets/:id`, so without the check the notice would follow
 *    the reporter to the next ticket they opened.
 *  - **Failures are named by position and name** — "Photo 2 (image.jpg)" —
 *    because an iPhone calls every photo `image.jpg`, and with the reason the
 *    server gave, so the reporter knows whether trying again can help.
 */
export function ReportOutcomeNotice({ outcome, ticketId }: ReportOutcomeNoticeProps) {
  const headingRef = useRef<HTMLHeadingElement>(null)
  const focused = useRef(false)
  const headingId = useId()
  const belongs = outcome.ticketId === ticketId

  useEffect(() => {
    if (!belongs || focused.current) return
    focused.current = true
    headingRef.current?.focus()
  }, [belongs])

  if (!belongs) return null

  const failed = outcome.failed.length
  const tone = failed > 0 ? 'warning' : 'success'
  const Icon = failed > 0 ? TriangleAlert : CircleCheck

  return (
    <section
      aria-labelledby={headingId}
      className={cn(
        'flex gap-3 rounded-md border border-border p-4',
        tone === 'warning' ? 'bg-warning-subtle' : 'bg-success-subtle',
      )}
    >
      <Icon
        size={22}
        aria-hidden="true"
        className={cn(
          'mt-1 shrink-0',
          tone === 'warning' ? 'text-warning-strong' : 'text-success-strong',
        )}
      />
      <div className="flex min-w-0 flex-col gap-2">
        <h2
          id={headingId}
          ref={headingRef}
          tabIndex={-1}
          className={cn(
            'text-base font-bold',
            tone === 'warning' ? 'text-warning-strong' : 'text-success-strong',
          )}
        >
          {failed > 0
            ? `Your report was sent, but ${photos(failed)} could not be attached`
            : outcome.attached > 0
              ? `Your report was sent with ${photos(outcome.attached)}`
              : 'Your report was sent'}
        </h2>

        {failed > 0 ? (
          <>
            <ul className="flex flex-col gap-1 text-sm text-ink">
              {outcome.failed.map((failure) => (
                <li key={failure.index}>
                  <span className="font-semibold">
                    Photo {failure.index} ({failure.name})
                  </span>{' '}
                  — {failure.message}
                </li>
              ))}
            </ul>
            <p className="text-sm text-ink">
              {outcome.attached > 0 ? `The other ${photos(outcome.attached)} arrived. ` : ''}
              Add the missing ones again under Photos &amp; files below.
            </p>
          </>
        ) : (
          <p className="text-sm text-ink">
            The IT team can see it now, and you can follow how it is going on this page.
          </p>
        )}
      </div>
    </section>
  )
}

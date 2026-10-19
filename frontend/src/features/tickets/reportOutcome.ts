/**
 * What happened to a report's photos, carried from the report form to the new
 * ticket's page (SDD DD-80).
 *
 * The form creates the ticket and then uploads the photos one by one; the
 * reporter lands on the ticket either way. This is how that page learns which
 * photos made it, so it can say so once — and open "Photos & files" when some
 * did not.
 *
 * It travels as router state, not a query string: it is a one-off message, it
 * must not survive being bookmarked or shared, and the ticket page clears it as
 * soon as it has read it.
 */

export interface ReportPhotoFailure {
  /** The photo's position in the form, from 1 — iPhone photos are all "image.jpg". */
  index: number
  name: string
  message: string
}

export interface ReportOutcome {
  /** The ticket it belongs to; any other ticket page ignores it. */
  ticketId: string
  attached: number
  failed: ReportPhotoFailure[]
}

/** The router state the report form navigates with. */
export interface ReportOutcomeState {
  reportOutcome: ReportOutcome
}

function isFailure(value: unknown): value is ReportPhotoFailure {
  if (typeof value !== 'object' || value === null) return false
  const candidate = value as Record<string, unknown>
  return (
    typeof candidate.index === 'number' &&
    typeof candidate.name === 'string' &&
    typeof candidate.message === 'string'
  )
}

/**
 * The outcome in `location.state`, or `null`.
 *
 * Router state is whatever the history entry holds — including state written
 * by another page, or a stale shape after a deploy — so it is checked field by
 * field rather than cast.
 */
export function readReportOutcome(state: unknown): ReportOutcome | null {
  if (typeof state !== 'object' || state === null) return null
  const outcome = (state as Record<string, unknown>).reportOutcome
  if (typeof outcome !== 'object' || outcome === null) return null

  const candidate = outcome as Record<string, unknown>
  if (typeof candidate.ticketId !== 'string' || candidate.ticketId === '') return null
  if (typeof candidate.attached !== 'number') return null
  if (!Array.isArray(candidate.failed) || !candidate.failed.every(isFailure)) return null

  return {
    ticketId: candidate.ticketId,
    attached: candidate.attached,
    failed: candidate.failed,
  }
}

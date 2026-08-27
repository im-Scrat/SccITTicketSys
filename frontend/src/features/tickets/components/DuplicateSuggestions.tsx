import { Alert, Skeleton } from '@/components/ui'
import { useDuplicateCheck } from '../hooks/queries'
import { TicketCardItem } from './TicketCardItem'

interface DuplicateSuggestionsProps {
  /** What the reporter has typed so far — usually the title. */
  search: string
  /** The machine they picked, which weights the match heavily. */
  pcUnit?: string
}

/**
 * "Someone may have already reported this" (SRS FR-TKT-011, UCS-02).
 *
 * Shown *while the reporter is still writing*, not after they submit. Telling
 * someone their ticket was a duplicate once they have finished describing it
 * wastes the effort they just spent and teaches them the form is not listening;
 * the whole value is in offering the existing ticket early enough that adding an
 * upvote is the easier path.
 *
 * **This is text similarity, not AI.** It is a ranked search over the stored
 * `tsvector`, boosted for the same PC unit, and it is labelled as "similar
 * reports" rather than implying analysis. Genuine AI duplicate detection is a
 * later phase, and claiming it now would be a promise the system cannot keep.
 */
export function DuplicateSuggestions({ search, pcUnit }: DuplicateSuggestionsProps) {
  const { data, isFetching, isError } = useDuplicateCheck(search, pcUnit)

  if (isError) return null

  if (isFetching && (data ?? []).length === 0) {
    return <Skeleton className="h-32 rounded-lg" />
  }

  const matches = data ?? []
  if (matches.length === 0) return null

  return (
    <section className="flex flex-col gap-5">
      <Alert tone="info" title="Similar reports already exist">
        If one of these is the same problem, upvote it instead of filing again — it tells the IT
        team how many people are affected, and you will see the same updates.
      </Alert>

      <ul className="flex flex-col gap-4">
        {matches.map((ticket) => (
          <li key={ticket.id}>
            <TicketCardItem ticket={ticket} compact />
          </li>
        ))}
      </ul>
    </section>
  )
}

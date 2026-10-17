import { Skeleton } from '@/components/ui'
import { useTicketFeed } from '../hooks/queries'
import { TicketCardItem } from './TicketCardItem'

/**
 * Phase 2.9 — "Already reported on this PC", shown the moment a machine is
 * chosen on the report form.
 *
 * Exact, not similar: the open tickets the community feed already lists for
 * that one PC (the feed's `pc_unit` filter), so the reporter can upvote or
 * comment instead of filing the same fault twice. Same restricted projection
 * as the feed — the reporter sees "A colleague", never a name. Similar-title
 * matches stay in DuplicateSuggestions below the form.
 *
 * The cards' vote buttons are `type="button"`, so they never submit the form
 * they sit inside.
 */
export function PcAlreadyReported({ pcUnit }: { pcUnit: string }) {
  const feed = useTicketFeed({ pc_unit: pcUnit, sort: 'recent', per_page: 5 }, Boolean(pcUnit))
  const tickets = feed.data?.pages.flatMap((page) => page.data) ?? []

  if (!pcUnit || feed.isError) return null
  if (feed.isLoading) return <Skeleton className="h-24 rounded-lg" />
  if (tickets.length === 0) {
    return (
      <p role="status" className="text-sm text-muted">
        No open reports for this PC yet.
      </p>
    )
  }

  return (
    <section aria-labelledby="pc-already-reported" className="flex flex-col gap-3">
      <div>
        <h3 id="pc-already-reported" className="text-base font-semibold text-ink-strong">
          Already reported on this PC · {tickets.length}
          {feed.hasNextPage ? '+' : ''}
        </h3>
        <p className="mt-1 text-sm text-muted">
          If one of these is your problem, upvote it or add a comment instead of reporting it again.
        </p>
      </div>
      {/* Compact rows of one bordered list, not cards: this sits inside the form's section. */}
      <ul className="divide-y divide-border rounded-md border border-border">
        {tickets.map((ticket) => (
          <li key={ticket.id}>
            <TicketCardItem ticket={ticket} compact />
          </li>
        ))}
      </ul>
    </section>
  )
}

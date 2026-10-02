import { ArrowRight } from 'lucide-react'
import { Link } from 'react-router-dom'
import { Alert, Button, EmptyState, Skeleton } from '@/components/ui'
import { useTicketFeed } from '../hooks/queries'
import type { TicketParams } from '../types'
import { TicketCardItem } from './TicketCardItem'

const PARAMS: TicketParams = { sort: 'recent', per_page: 10 }

/**
 * Phase 2.9 — the community feed as the teacher's dashboard (D-A).
 *
 * The same restricted projection and the same cards as the full feed page,
 * newest first. Each card says "A colleague" for anyone else's report: the
 * reader learns *that* a fault is known, never *who* reported it.
 *
 * "Show more" is an explicit button, not scroll-triggered loading — the same
 * decision TicketFeedPage records: infinite scroll takes the page's end away
 * from keyboard users and hides how much is left. Filters and other sorts live
 * on the full feed, one link away.
 */
export function CommunityFeedPanel({ canReport }: { canReport: boolean }) {
  const feed = useTicketFeed(PARAMS)
  const tickets = feed.data?.pages.flatMap((page) => page.data) ?? []

  return (
    <section aria-labelledby="community-feed-heading" className="flex min-w-0 flex-col gap-4">
      <div className="flex flex-wrap items-baseline justify-between gap-3">
        <div>
          <h2 id="community-feed-heading" className="text-lg font-semibold text-ink-strong">
            Reported problems
          </h2>
          <p className="mt-1 text-sm text-muted">
            Check here before reporting. If it is already listed, an upvote tells the IT team how
            many people it affects.
          </p>
        </div>
        <Link
          to="/app/tickets"
          className="inline-flex items-center gap-1.5 rounded-sm text-sm font-semibold text-primary-strong hover:underline"
        >
          Search and filter all
          <ArrowRight size={16} aria-hidden="true" />
        </Link>
      </div>

      {feed.isError ? (
        <Alert tone="error" title="The feed could not be loaded">
          Use Refresh above to try again.
        </Alert>
      ) : feed.isLoading ? (
        <div className="flex flex-col gap-4" aria-busy="true">
          {Array.from({ length: 3 }).map((_, index) => (
            <Skeleton key={index} className="h-40 rounded-lg" />
          ))}
        </div>
      ) : tickets.length === 0 ? (
        <EmptyState
          title="Nothing is reported right now"
          description={
            canReport
              ? 'If something is not working, report it — it will appear here so others know it is being handled.'
              : 'When a problem is reported it appears here.'
          }
        />
      ) : (
        <>
          <ul className="flex flex-col gap-4">
            {tickets.map((ticket) => (
              <li key={ticket.id}>
                <TicketCardItem ticket={ticket} />
              </li>
            ))}
          </ul>

          {feed.hasNextPage && (
            <div className="flex justify-center">
              <Button
                variant="secondary"
                onClick={() => void feed.fetchNextPage()}
                loading={feed.isFetchingNextPage}
              >
                Show more
              </Button>
            </div>
          )}
        </>
      )}
    </section>
  )
}

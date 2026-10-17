import { Alert, Button, EmptyState, Skeleton } from '@/components/ui'
import { PanelHeader } from '@/features/dashboard/components/PanelHeader'
import { cn } from '@/lib/cn'
import { useTicketFeed } from '../hooks/queries'
import type { TicketParams } from '../types'
import { TicketCardItem } from './TicketCardItem'

const PARAMS: TicketParams = { sort: 'recent', per_page: 10 }

const HEADING_ID = 'community-feed-heading'

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
 *
 * **Two pieces, not one.** The heading and the content are separate components
 * so the dashboard can seat them in a grid shared with the "My requests" panel:
 * both headings in one row and both bodies in the next, which is what makes the
 * two columns' tops and baselines line up whatever the heading wraps to.
 */
export function CommunityFeedHeader({ className }: { className?: string }) {
  return (
    <div className={cn('min-w-0', className)}>
      <PanelHeader
        id={HEADING_ID}
        title="Reported problems"
        description="Check here before reporting. If it is already listed, an upvote tells the IT team how many people it affects."
        link={{ to: '/app/tickets', label: 'Search and filter all' }}
      />
    </div>
  )
}

export function CommunityFeedBody({
  canReport,
  className,
}: {
  canReport: boolean
  className?: string
}) {
  const feed = useTicketFeed(PARAMS)
  const tickets = feed.data?.pages.flatMap((page) => page.data) ?? []

  return (
    <section aria-labelledby={HEADING_ID} className={cn('flex min-w-0 flex-col gap-4', className)}>
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

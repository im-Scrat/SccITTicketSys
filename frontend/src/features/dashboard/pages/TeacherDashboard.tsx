import { Plus } from 'lucide-react'
import { Alert, ButtonLink, Skeleton } from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import {
  CommunityFeedBody,
  CommunityFeedHeader,
} from '@/features/tickets/components/CommunityFeedPanel'
import { DashboardHeader } from '../components/DashboardHeader'
import { RequestSummaryHeader, RequestSummaryList } from '../components/RequestSummary'
import { ServerWidget } from '../components/ServerWidget'
import { otherWidgets, pickWidgets } from '../lib/widgets'
import { useDashboard } from '../hooks/queries'
import type { KpiWidget } from '../types'

/** The one panel this page draws itself — see {@link RequestSummaryList}. */
const SUMMARY_WIDGET = 'my-requests'

/**
 * Phase 2.9 — the requester's dashboard: **the community feed first**, and
 * their own reports beside it.
 *
 * A teacher comes here for one of two reasons — something is broken, or they
 * want to know where their report stands. The feed answers "is it already
 * known?" before they report a duplicate; the side column answers "where is
 * mine?". The quick-actions panel is dropped: its one action is the header's
 * primary button and the nav's first item.
 *
 * **The two columns share one grid.** Both headings sit in the first row and
 * both bodies in the second, so "Reported problems" and "My requests" have the
 * same title, the same baseline and the same top edge for their content — which
 * they cannot be made to have by matching two independent stacks, because a
 * heading that wraps differently moves everything under it. In the DOM the order
 * is feed heading, feed, requests heading, requests; the grid only places them
 * side by side from `xl`, so a narrow screen and a screen reader read them as
 * two sections, one after the other.
 *
 * "My requests" is drawn as three links, one per figure, each opening the list
 * of exactly those tickets — not as the shared KPI cards, which are laid out by
 * viewport width and do not fit this 24rem column. Every panel in the side
 * column takes the `section` variant, so it is headed and framed like the feed.
 *
 * The "Recent requests" list is dropped at the owner's request; the server
 * still sends it, so it is filtered out here rather than removed from the API.
 */
export default function TeacherDashboard() {
  const { user, hasPermission } = useAuth()
  const { data, isLoading, isError, isFetching, refetch } = useDashboard()
  const canReport = hasPermission('tickets.create')

  const widgets = data?.widgets ?? []
  const summary = pickWidgets(widgets, [SUMMARY_WIDGET]).find(
    (widget): widget is KpiWidget => widget.type === 'kpi',
  )
  const side = otherWidgets(widgets, ['quick-actions', 'my-recent-requests', SUMMARY_WIDGET])

  return (
    <div className="flex flex-col gap-8">
      <DashboardHeader
        title={user?.first_name ? `Welcome, ${user.first_name}` : 'Dashboard'}
        subtitle="What is reported right now, and where your own reports stand."
        generatedAt={data?.generated_at}
        refreshing={isFetching && !isLoading}
        onRefresh={() => void refetch()}
      >
        {canReport && (
          <ButtonLink to="/app/tickets/new" leftIcon={<Plus size={20} aria-hidden="true" />}>
            Report a problem
          </ButtonLink>
        )}
      </DashboardHeader>

      <div className="grid grid-cols-1 gap-x-8 gap-y-4 xl:grid-cols-[minmax(0,1fr)_24rem] xl:items-start">
        <CommunityFeedHeader className="xl:col-start-1 xl:row-start-1" />
        <CommunityFeedBody canReport={canReport} className="xl:col-start-1 xl:row-start-2" />

        {/* Stacked, this heading follows the feed; the extra top margin keeps the
            two sections visibly apart there and goes away when they sit side by side. */}
        {summary && (
          <RequestSummaryHeader
            widget={summary}
            className="mt-4 xl:col-start-2 xl:row-start-1 xl:mt-0"
          />
        )}

        <aside
          aria-label="Your reports"
          className="flex min-w-0 flex-col gap-6 xl:col-start-2 xl:row-start-2"
        >
          {isError && (
            <Alert tone="error">Your summary could not be loaded. Use Refresh to try again.</Alert>
          )}
          {isLoading ? (
            <Skeleton className="h-68 rounded-lg" />
          ) : (
            <>
              {summary && <RequestSummaryList widget={summary} />}
              {side.map((widget) => (
                <ServerWidget key={widget.key} widget={widget} variant="section" />
              ))}
            </>
          )}
        </aside>
      </div>
    </div>
  )
}

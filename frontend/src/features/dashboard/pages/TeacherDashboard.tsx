import { Plus } from 'lucide-react'
import { Alert, ButtonLink, Skeleton } from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { CommunityFeedPanel } from '@/features/tickets/components/CommunityFeedPanel'
import { DashboardHeader } from '../components/DashboardHeader'
import { ServerWidget } from '../components/ServerWidget'
import { otherWidgets } from '../lib/widgets'
import { useDashboard } from '../hooks/queries'

/**
 * Phase 2.9 — the requester's dashboard: **the community feed first**, and
 * their own reports beside it.
 *
 * A teacher comes here for one of two reasons — something is broken, or they
 * want to know where their report stands. The feed answers "is it already
 * known?" before they report a duplicate; the side column answers "where is
 * mine?". The quick-actions panel is dropped: its one action is the header's
 * primary button and the nav's first item.
 */
export default function TeacherDashboard() {
  const { user, hasPermission } = useAuth()
  const { data, isLoading, isError, isFetching, refetch } = useDashboard()
  const canReport = hasPermission('tickets.create')
  const side = otherWidgets(data?.widgets ?? [], ['quick-actions'])

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

      <div className="grid grid-cols-1 items-start gap-8 xl:grid-cols-[minmax(0,1fr)_24rem]">
        <CommunityFeedPanel canReport={canReport} />

        <aside aria-label="Your reports" className="flex min-w-0 flex-col gap-6">
          {isError && (
            <Alert tone="error">Your summary could not be loaded. Use Refresh to try again.</Alert>
          )}
          {isLoading ? (
            <>
              <Skeleton className="h-28" />
              <Skeleton className="h-48" />
            </>
          ) : (
            side.map((widget) => <ServerWidget key={widget.key} widget={widget} />)
          )}
        </aside>
      </div>
    </div>
  )
}

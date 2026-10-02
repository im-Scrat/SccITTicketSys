import { ListChecks } from 'lucide-react'
import { Alert, ButtonLink, Skeleton } from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { cn } from '@/lib/cn'
import { DashboardHeader } from '../components/DashboardHeader'
import { ScanGuide } from '../components/ScanGuide'
import { ServerWidget } from '../components/ServerWidget'
import { otherWidgets, pickWidgets } from '../lib/widgets'
import { useDashboard } from '../hooks/queries'

/** Work first: the queue figures, then the assigned list, then everything else. */
const LEAD = ['my-work']
const PRIMARY = ['my-assignments', 'my-maintenance', 'maintenance-schedule']

/**
 * Phase 2.9 — the technician's dashboard: **their assigned work**, ordered the
 * way a shift runs. What is waiting on me, what is at risk, what is scheduled —
 * and, beside it, how to record work at the machine (by its QR label, D-C).
 *
 * No community feed and no organization-wide figures: the feed is refused to
 * technicians (DD-40), and the server sends this role only its own panels.
 */
export default function TechnicianDashboard() {
  const { user } = useAuth()
  const { data, isLoading, isError, isFetching, refetch } = useDashboard()
  const widgets = data?.widgets ?? []
  const lead = pickWidgets(widgets, LEAD)
  const primary = pickWidgets(widgets, PRIMARY)
  const rest = otherWidgets(widgets, [...LEAD, ...PRIMARY])

  return (
    <div className="flex flex-col gap-8">
      <DashboardHeader
        title={user?.first_name ? `Welcome, ${user.first_name}` : 'Your work'}
        subtitle="Your assigned work, what is at risk, and what is scheduled next."
        generatedAt={data?.generated_at}
        refreshing={isFetching && !isLoading}
        onRefresh={() => void refetch()}
      >
        <ButtonLink
          to="/app/tickets/assigned"
          leftIcon={<ListChecks size={20} aria-hidden="true" />}
        >
          Open my work
        </ButtonLink>
      </DashboardHeader>

      {isError && (
        <Alert tone="error">
          Your dashboard could not be loaded. Use Refresh to try again — your work queue still
          works.
        </Alert>
      )}

      {isLoading ? (
        <div className="flex flex-col gap-6">
          <Skeleton className="h-24" />
          <div className="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <Skeleton className="h-64" />
            <Skeleton className="h-40" />
          </div>
        </div>
      ) : (
        <div
          className={cn(
            'flex flex-col gap-6 transition-opacity motion-reduce:transition-none',
            isFetching && 'opacity-60',
          )}
        >
          {lead.map((widget) => (
            <ServerWidget key={widget.key} widget={widget} />
          ))}

          <div className="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <div className="flex min-w-0 flex-col gap-6">
              {primary.map((widget) => (
                <ServerWidget key={widget.key} widget={widget} />
              ))}
            </div>
            <aside aria-label="Tools and equipment" className="flex min-w-0 flex-col gap-6">
              <ScanGuide />
              {rest.map((widget) => (
                <ServerWidget key={widget.key} widget={widget} />
              ))}
            </aside>
          </div>
        </div>
      )}
    </div>
  )
}

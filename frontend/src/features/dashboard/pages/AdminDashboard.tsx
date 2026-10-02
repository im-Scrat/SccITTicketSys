import { Alert, Skeleton } from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { TechnicianSummaryTable } from '@/features/technicians/components/TechnicianSummaryTable'
import { useTechnicianOverview } from '@/features/technicians/hooks/queries'
import { cn } from '@/lib/cn'
import { AdminTicketQueue } from '../components/AdminTicketQueue'
import { DashboardHeader } from '../components/DashboardHeader'
import { LiveStatus } from '../components/LiveStatus'
import { ServerWidget } from '../components/ServerWidget'
import { otherWidgets, pickWidgets } from '../lib/widgets'
import { WidgetCard } from '../components/WidgetCard'
import { useAdminDashboardChannel } from '../hooks/useAdminDashboardChannel'
import { useDashboard } from '../hooks/queries'

const LEAD = ['service-desk']
const FEED = ['recent-activity']

/**
 * Phase 2.9 — the administrator's dashboard: **oversight, live**.
 *
 * Top to bottom in the order an administrator acts: the service-desk figures;
 * the two queues that need a decision now (reports a teacher marked NOT FIXED,
 * and tickets with no owner) with an Assign button on each row; how each
 * technician's work stands; then the rest of the estate. Every panel updates
 * by itself while the `admin.dashboard` channel is live — the header says
 * whether it is.
 *
 * Figures are labelled for what they are: the server's KPIs are recorded
 * counts; the technician table separates recorded from calculated. Predictive
 * maintenance stays on its own page.
 */
export default function AdminDashboard() {
  const { user, hasPermission } = useAuth()
  const { data, isLoading, isError, isFetching, refetch } = useDashboard()
  const { live } = useAdminDashboardChannel()
  const canMonitor = hasPermission('users.view')
  const technicians = useTechnicianOverview(canMonitor)

  const widgets = data?.widgets ?? []
  const lead = pickWidgets(widgets, LEAD)
  const activity = pickWidgets(widgets, FEED)
  // The technician table supersedes the server's top-5 workload list — but
  // only when this administrator may read it.
  const rest = otherWidgets(widgets, [
    ...LEAD,
    ...FEED,
    ...(canMonitor ? ['technician-workload'] : []),
  ])
  const restKpis = rest.filter((widget) => widget.type === 'kpi')
  const restPanels = rest.filter((widget) => widget.type !== 'kpi')

  const busiest = [...(technicians.data ?? [])]
    .sort((a, b) => b.recorded.active_assignments - a.recorded.active_assignments)
    .slice(0, 6)

  return (
    <div className="flex flex-col gap-8">
      <DashboardHeader
        title={user?.first_name ? `Welcome, ${user.first_name}` : 'Dashboard'}
        subtitle="Operations across the whole organization."
        generatedAt={data?.generated_at}
        refreshing={isFetching && !isLoading}
        onRefresh={() => void refetch()}
      >
        <LiveStatus live={live} />
      </DashboardHeader>

      {isError && (
        <Alert tone="error">
          We couldn’t load your dashboard. Please retry — the rest of the console still works.
        </Alert>
      )}

      {isLoading ? (
        <div className="flex flex-col gap-6">
          <Skeleton className="h-24" />
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <Skeleton className="h-56" />
            <Skeleton className="h-56" />
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

          <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
            <AdminTicketQueue
              title="Escalated by the reporter"
              description="The reporter tried the AI’s suggestions and said it is not fixed."
              params={{ escalated: 1 }}
              href="/app/tickets/manage?escalated=1"
              empty="No escalations waiting."
            />
            <AdminTicketQueue
              title="Needs an owner"
              description="Open tickets no technician has been assigned to."
              params={{ technician: 'unassigned', sort: 'created_at', direction: 'asc' }}
              href="/app/tickets/manage?technician=unassigned"
              empty="Every open ticket has an owner."
            />
          </div>

          {canMonitor && (
            <WidgetCard
              title="Technicians"
              description="Recorded counts and figures calculated from recorded timestamps."
              href="/app/technicians"
              linkLabel="All technicians"
            >
              {technicians.isError ? (
                <Alert tone="error">Technician figures could not be loaded.</Alert>
              ) : technicians.isLoading ? (
                <Skeleton className="h-32" />
              ) : busiest.length === 0 ? (
                <p className="py-3 text-sm text-muted">There are no technician accounts yet.</p>
              ) : (
                <TechnicianSummaryTable
                  technicians={busiest}
                  compact
                  caption="Technicians with the most active work"
                />
              )}
            </WidgetCard>
          )}

          {restKpis.map((widget) => (
            <ServerWidget key={widget.key} widget={widget} />
          ))}

          <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
            {[...activity, ...restPanels].map((widget) => (
              <ServerWidget key={widget.key} widget={widget} />
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

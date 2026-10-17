import { ListChecks } from 'lucide-react'
import { Alert, ButtonLink, Skeleton } from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { DashboardHeader } from '../components/DashboardHeader'
import { ScanGuide } from '../components/ScanGuide'
import { ServerWidget } from '../components/ServerWidget'
import { decorateTechnicianWidget } from '../lib/technicianLinks'
import { otherWidgets, pickWidgets } from '../lib/widgets'
import { useDashboard } from '../hooks/queries'
import type { DashboardWidget } from '../types'

/** Work first: the queue figures, then the assigned list, then everything else. */
const LEAD = ['my-work']
const PRIMARY = ['my-assignments', 'my-maintenance', 'maintenance-schedule']

/**
 * Phase 2.9 — the technician's dashboard: **their assigned work**, ordered the
 * way a shift runs. What is waiting on me, what is overdue, what is scheduled —
 * and, beside it, how to record work at the machine (by its QR label, D-C).
 *
 * **Everything on it opens something.** Each figure is a link to the list it
 * counts, and each row opens its ticket or maintenance record (SDD DD-81); where
 * those links point is `lib/technicianLinks.ts`, and the parity tests prove each
 * list is the same set as its figure. Two asset panels that repeated the assets
 * page are not shown — the figure "Assigned to me" is kept, as a link to it.
 *
 * No community feed and no organization-wide figures: the feed is refused to
 * technicians (DD-40), and the server sends this role only its own panels.
 * Every panel uses the `section` variant — heading above a frame — so the page
 * has one heading treatment rather than three.
 */
export default function TechnicianDashboard() {
  const { user } = useAuth()
  const { data, isLoading, isError, isFetching, refetch } = useDashboard()
  const widgets = (data?.widgets ?? [])
    .map(decorateTechnicianWidget)
    .filter((widget): widget is DashboardWidget => widget !== null)
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
        <DashboardSkeleton />
      ) : (
        <div className="flex flex-col gap-6">
          {lead.map((widget) => (
            <ServerWidget key={widget.key} widget={widget} variant="section" />
          ))}

          <div className="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <div className="flex min-w-0 flex-col gap-6">
              {primary.map((widget) => (
                <ServerWidget key={widget.key} widget={widget} variant="section" />
              ))}
            </div>
            <aside aria-label="Tools and equipment" className="flex min-w-0 flex-col gap-6">
              <ScanGuide />
              {rest.map((widget) => (
                <ServerWidget key={widget.key} widget={widget} variant="section" />
              ))}
            </aside>
          </div>
        </div>
      )}
    </div>
  )
}

/**
 * The loading state in the shape of the page: a heading and four figure cards,
 * then the list panels beside the aside. A skeleton that does not match the
 * layout makes the content jump when it arrives. A refetch, by contrast, leaves
 * the content as it is — the header's Refresh button already says it is working,
 * and dimming a page you are reading every 30 seconds is noise.
 */
function DashboardSkeleton() {
  return (
    <div className="flex flex-col gap-6" aria-busy="true" aria-label="Loading your dashboard">
      <div className="flex flex-col gap-4">
        <Skeleton className="h-7 w-40" />
        <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
          {Array.from({ length: 4 }).map((_, index) => (
            <Skeleton key={index} className="h-24 rounded-md" />
          ))}
        </div>
      </div>
      <div className="grid grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
        <div className="flex flex-col gap-6">
          <Skeleton className="h-64 rounded-lg" />
          <Skeleton className="h-40 rounded-lg" />
        </div>
        <div className="flex flex-col gap-6">
          <Skeleton className="h-36 rounded-lg" />
          <Skeleton className="h-28 rounded-lg" />
        </div>
      </div>
    </div>
  )
}

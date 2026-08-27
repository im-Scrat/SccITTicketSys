import { RefreshCw } from 'lucide-react'
import { Alert, Button, Skeleton } from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { cn } from '@/lib/cn'
import { formatRelative } from '@/lib/datetime'
import { AnnouncementList } from '../components/AnnouncementList'
import { DistributionMeter } from '../components/DistributionMeter'
import { KpiRow } from '../components/KpiRow'
import { QuickActions } from '../components/QuickActions'
import { WidgetCard } from '../components/WidgetCard'
import { WidgetList } from '../components/WidgetList'
import { useDashboard } from '../hooks/queries'
import type { DashboardWidget } from '../types'

const ROLE_INTRO: Record<string, string> = {
  administrator: 'Operations across the whole organization.',
  technician: 'Your queue, your maintenance, and what needs an owner.',
  teacher: 'Where your reported problems stand.',
}

/**
 * The role-aware landing dashboard (FR-DSH-001).
 *
 * The server decides both the layout (by role) and the content of each panel (by
 * permission), so this page renders whatever it is given — there is no
 * client-side filtering of privileged figures and no per-role page to keep in
 * sync. KPI rows span the full width; the remaining panels flow in two columns on
 * a wide screen and one on a narrow one.
 */
export default function DashboardPage() {
  useDocumentMeta({ title: 'Dashboard' })
  const { user } = useAuth()
  const { data, isLoading, isError, isFetching, refetch } = useDashboard()

  const widgets = data?.widgets ?? []
  const fullWidth = widgets.filter((widget) => widget.type === 'kpi')
  const rest = widgets.filter((widget) => widget.type !== 'kpi')

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-[-0.02em] text-ink-strong">
            {user?.first_name ? `Welcome, ${user.first_name}` : 'Dashboard'}
          </h1>
          <p className="mt-1 text-sm text-muted">
            {data ? (ROLE_INTRO[data.role] ?? 'Your workspace.') : 'Your workspace.'}
          </p>
        </div>

        <div className="flex items-center gap-3">
          {data?.generated_at && (
            <span className="text-xs text-muted tnum">
              Updated {formatRelative(data.generated_at)}
            </span>
          )}
          <Button
            variant="secondary"
            size="sm"
            leftIcon={<RefreshCw size={15} />}
            loading={isFetching && !isLoading}
            onClick={() => void refetch()}
          >
            Refresh
          </Button>
        </div>
      </header>

      {isError && (
        <Alert tone="error">
          We couldn’t load your dashboard. Please retry — the rest of the console still works.
        </Alert>
      )}

      {isLoading ? (
        <div className="flex flex-col gap-6">
          <Skeleton className="h-24" />
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2 lg:gap-6">
            <Skeleton className="h-56" />
            <Skeleton className="h-56" />
          </div>
        </div>
      ) : (
        // Hold the previous render at reduced opacity while refetching rather
        // than flashing skeletons and jumping the layout.
        <div
          className={cn(
            'flex flex-col gap-6 transition-opacity motion-reduce:transition-none',
            isFetching && 'opacity-60',
          )}
        >
          {fullWidth.map((widget) => (
            <Widget key={widget.key} widget={widget} />
          ))}

          {rest.length > 0 && (
            // `items-start` so a short panel (an empty activity feed) sizes to
            // its content instead of stretching to match a long neighbour.
            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-2 lg:gap-6">
              {rest.map((widget) => (
                <Widget key={widget.key} widget={widget} />
              ))}
            </div>
          )}

          {widgets.length === 0 && !isError && (
            <Alert tone="info">
              There is nothing on your dashboard yet. Panels appear here as the modules you can
              access start collecting data.
            </Alert>
          )}
        </div>
      )}
    </div>
  )
}

function Widget({ widget }: { widget: DashboardWidget }) {
  switch (widget.type) {
    case 'kpi':
      return (
        <section aria-labelledby={`${widget.key}-heading`}>
          <div className="mb-2.5 flex items-baseline justify-between gap-3">
            <h2 id={`${widget.key}-heading`} className="text-sm font-semibold text-ink-strong">
              {widget.title}
            </h2>
            {widget.description && <p className="text-xs text-muted">{widget.description}</p>}
          </div>
          <KpiRow items={widget.items} />
        </section>
      )

    case 'distribution':
      return (
        <WidgetCard title={widget.title} description={widget.description} href={widget.href}>
          <DistributionMeter rows={widget.rows} unit={widget.unit} emptyLabel={widget.empty} />
        </WidgetCard>
      )

    case 'list':
      return (
        <WidgetCard title={widget.title} description={widget.description} href={widget.href}>
          <WidgetList columns={widget.columns} rows={widget.rows} emptyLabel={widget.empty} />
        </WidgetCard>
      )

    case 'actions':
      return (
        <WidgetCard title={widget.title} description={widget.description}>
          <QuickActions items={widget.items} />
        </WidgetCard>
      )

    case 'announcements':
      return (
        <WidgetCard title={widget.title} description={widget.description}>
          <AnnouncementList rows={widget.rows} />
        </WidgetCard>
      )

    default:
      return null
  }
}

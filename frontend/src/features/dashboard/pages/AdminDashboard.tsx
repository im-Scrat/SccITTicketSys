import { isAxiosError } from 'axios'
import { BarChart3 } from 'lucide-react'
import { Alert, Button, EmptyState, ErrorState } from '@/components/ui'
import { ReportFilterToolbar } from '@/features/analytics/components/ReportFilterToolbar'
import { ReportActions } from '@/features/analytics/components/export/ReportActions'
import { useReportOptions, useTicketOverview } from '@/features/analytics/hooks/queries'
import { useReportFilters } from '@/features/analytics/hooks/useReportFilters'
import { useReportsAccess } from '@/features/analytics/hooks/useReportsAccess'
import { cn } from '@/lib/cn'
import { AdminDashboardSkeleton } from '../components/AdminDashboardSkeleton'
import { DashboardHeader } from '../components/DashboardHeader'
import { LiveStatus } from '../components/LiveStatus'
import { LocationComparisonPanel } from '../components/LocationComparisonPanel'
import { OverviewSummary } from '../components/OverviewSummary'
import { ReportedOverTimePanel } from '../components/ReportedOverTimePanel'
import { StatusDistributionPanel } from '../components/StatusDistributionPanel'
import { TicketOverviewKpis } from '../components/TicketOverviewKpis'
import { useAdminDashboardChannel } from '../hooks/useAdminDashboardChannel'

/**
 * The administrator's dashboard — the ticket overview (Phase 2.10; SRS
 * FR-DSH-009, FR-RPT-001/002).
 *
 * What is being reported, in what state, and where the problems are, for the
 * period chosen; every figure opens the exact ticket list it counts. Filters
 * live in the URL. Everything on the page is one population — tickets reported
 * in the period — computed on the server in the school's timezone, so the
 * KPIs, the line, the donut and the ranking always reconcile.
 *
 * Loaded lazily by DashboardPage, so teachers and technicians never download
 * the chart library. Live: the administrators' channel invalidates the
 * overview whenever a ticket changes elsewhere.
 */
export default function AdminDashboard() {
  const { live } = useAdminDashboardChannel()
  const { canView } = useReportsAccess()
  const { params, patch, reset, activeCount } = useReportFilters()
  const options = useReportOptions(canView)
  const overview = useTicketOverview(params, canView)

  const data = overview.data
  const meta = data?.meta
  const validationMessage = overviewValidationMessage(overview.error)
  const refreshing = overview.isFetching && !overview.isLoading

  const subtitle = meta
    ? `${meta.period.range_label} · ${meta.timezone}${
        meta.comparison?.available ? ` · compared with ${meta.comparison.range_label}` : ''
      }`
    : 'Tickets reported, their status, and where problems are.'

  return (
    <div className="flex flex-col gap-8">
      <DashboardHeader
        title="Ticket overview"
        subtitle={subtitle}
        generatedAt={meta?.generated_at}
        refreshing={refreshing}
        onRefresh={() => {
          void overview.refetch()
          void options.refetch()
        }}
      >
        <LiveStatus live={live} />
        {canView && (
          <ReportActions
            reportType="ticket_overview"
            params={params}
            printPath="/app/dashboard/print"
          />
        )}
      </DashboardHeader>

      {!canView ? (
        <Alert tone="info" title="The ticket overview is for administrators with report access">
          Ask an administrator if you need these figures.
        </Alert>
      ) : (
        <>
          <ReportFilterToolbar
            params={params}
            patch={patch}
            reset={reset}
            activeCount={activeCount}
            options={options.data}
            meta={meta}
          />

          {validationMessage ? (
            <Alert tone="warning" title="These filters can't be shown">
              <span className="flex flex-col items-start gap-3">
                {validationMessage}
                <Button variant="secondary" size="sm" onClick={reset}>
                  Reset filters
                </Button>
              </span>
            </Alert>
          ) : overview.isError && !data ? (
            <ErrorState
              title="The ticket overview could not be loaded"
              description="The rest of the console still works. Try again in a moment."
              onRetry={() => void overview.refetch()}
              retrying={overview.isFetching}
            />
          ) : !data ? (
            <AdminDashboardSkeleton />
          ) : (
            <div
              className={cn(
                'flex flex-col gap-8 transition-opacity duration-200',
                refreshing && 'opacity-60',
              )}
              aria-busy={refreshing}
            >
              <p className="sr-only" aria-live="polite">
                {refreshing ? 'Updating the figures.' : ''}
              </p>

              {data.data.kpis.reported.value === 0 &&
              data.data.kpis.resolved_in_period.value === 0 ? (
                <EmptyState
                  icon={<BarChart3 size={22} />}
                  title={`No tickets were reported in ${meta?.period.range_label.replace(' (to date)', '')}`}
                  description={
                    meta?.data_starts_at && meta.period.to < meta.data_starts_at
                      ? `Ticket records begin on ${meta.data_starts_at}. Choose a later period.`
                      : activeCount > 0
                        ? 'Nothing matches these filters. Widen the period or reset the filters.'
                        : 'New reports appear here as they come in.'
                  }
                  action={
                    activeCount > 0 ? (
                      <Button variant="secondary" size="sm" onClick={reset}>
                        Reset filters
                      </Button>
                    ) : undefined
                  }
                />
              ) : (
                <>
                  <TicketOverviewKpis overview={data} />
                  <div className="grid grid-cols-1 gap-8 xl:grid-cols-12">
                    <div className="flex min-w-0 flex-col xl:col-span-7 [&>section]:flex-1">
                      <ReportedOverTimePanel
                        overview={data}
                        granularity={params.granularity}
                        onGranularity={(value) => patch({ granularity: value })}
                      />
                    </div>
                    <div className="flex min-w-0 flex-col xl:col-span-5 [&>section]:flex-1">
                      <StatusDistributionPanel overview={data} />
                    </div>
                  </div>
                  <LocationComparisonPanel overview={data} />
                  <OverviewSummary overview={data} />
                </>
              )}
            </div>
          )}
        </>
      )}
    </div>
  )
}

/** The server's 422 message for the first offending filter, in its own words. */
function overviewValidationMessage(error: unknown): string | null {
  if (!isAxiosError(error) || error.response?.status !== 422) return null
  const errors = (error.response.data as { errors?: Record<string, string[]> } | undefined)?.errors
  const first = errors ? Object.values(errors)[0]?.[0] : undefined
  return first ?? 'One of the filters is not valid.'
}

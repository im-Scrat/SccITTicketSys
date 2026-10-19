import { isAxiosError } from 'axios'
import { useEffect, useMemo, useState } from 'react'
import { Alert, Button, ErrorState, Select, Skeleton } from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { DashboardHeader } from '@/features/dashboard/components/DashboardHeader'
import { useAdminDashboardChannel } from '@/features/dashboard/hooks/useAdminDashboardChannel'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { cn } from '@/lib/cn'
import { ReportActions } from '../components/export/ReportActions'
import { InsightsSection } from '../components/overall/InsightsSection'
import { OverallExtraFilters } from '../components/overall/OverallExtraFilters'
import { SectionView } from '../components/overall/SectionView'
import { ReportFilterToolbar } from '../components/ReportFilterToolbar'
import { TicketOverviewKpis } from '../components/TicketOverviewKpis'
import { useOverallSection, useReportOptions, useTicketOverview } from '../hooks/queries'
import { REPORT_PARAM_KEYS, useOverallFilters } from '../hooks/useReportFilters'
import { useReportsAccess } from '../hooks/useReportsAccess'
import type { OverallSectionKey, ReportParams } from '../types'

const SECTION_LABELS: Record<Exclude<OverallSectionKey, 'insights'>, string> = {
  overview: 'Overview',
  tickets: 'Tickets',
  equipment: 'Equipment',
  repairs: 'Repairs & maintenance',
  locations: 'Locations',
}

/**
 * Overall Reports (Phase 2.10 R4; SRS FR-RPT-001/002/010): tickets, equipment,
 * repairs and locations, computed from the records, one section at a time.
 *
 * Filters and the section live in the URL. Only the section being read is
 * fetched; Export and Print assemble every section the viewer may see. A
 * report someone is reading is never refetched under them: when the live
 * channel reports activity, a notice offers to refresh instead.
 */
export default function OverallReportsPage() {
  useDocumentMeta({ title: 'Overall reports' })
  const { canView } = useReportsAccess()
  const { hasPermission } = useAuth()
  const { params, section, patch, reset, activeCount } = useOverallFilters()
  const { lastChange } = useAdminDashboardChannel()
  const [changedSince, setChangedSince] = useState(false)

  // The shared report vocabulary only — what the ticket overview and the
  // toolbar understand; the overall-only filters travel separately.
  const reportParams = useMemo<ReportParams>(() => {
    const out: ReportParams = {}
    for (const key of REPORT_PARAM_KEYS) {
      if (params[key] !== undefined) out[key] = params[key]
    }
    return out
  }, [params])

  const options = useReportOptions(canView)
  const overviewMeta = useTicketOverview(reportParams, canView && hasPermission('tickets.view'))
  // AI insights draw on tickets, equipment and repairs (the server checks the same).
  const canViewInsights =
    canView &&
    hasPermission('tickets.view') &&
    hasPermission('assets.view') &&
    hasPermission('maintenance.view')
  const isInsights = section === 'insights'
  const current = useOverallSection(section, params, canView && !isInsights)
  const data = current.data
  const baseSections: Array<{ key: OverallSectionKey; label: string }> =
    data?.meta.sections ??
    (Object.keys(SECTION_LABELS) as Array<keyof typeof SECTION_LABELS>).map((key) => ({
      key,
      label: SECTION_LABELS[key],
    }))
  const sections = canViewInsights
    ? [...baseSections, { key: 'insights' as const, label: 'AI insights' }]
    : baseSections

  // A change on the live channel after the figures loaded: say so, never refetch
  // under the reader. A fresh load (refresh, new filters) clears the notice.
  useEffect(() => {
    if (lastChange !== null) setChangedSince(true)
  }, [lastChange])
  useEffect(() => {
    setChangedSince(false)
  }, [current.dataUpdatedAt])
  const status = isAxiosError(current.error) ? current.error.response?.status : undefined
  const validation =
    status === 422
      ? (Object.values(
          (current.error as { response?: { data?: { errors?: Record<string, string[]> } } })
            .response?.data?.errors ?? {},
        )[0]?.[0] ?? 'One of the filters is not valid.')
      : null

  return (
    <div className="mx-auto flex w-full max-w-[96rem] flex-col gap-8">
      <DashboardHeader
        title="Overall reports"
        subtitle={
          data
            ? `${data.meta.period.range_label} · ${data.meta.timezone} · tickets, equipment, repairs and locations, computed from the records`
            : 'Tickets, equipment, repairs and locations, computed from the records.'
        }
        generatedAt={data?.meta.generated_at}
        refreshing={current.isFetching && !current.isLoading}
        onRefresh={() => void current.refetch()}
      >
        {canView && (
          <ReportActions reportType="overall" params={params} printPath="/app/reports/print" />
        )}
      </DashboardHeader>

      {!canView ? (
        <Alert tone="info" title="Overall reports are for administrators with report access">
          Ask an administrator if you need these figures.
        </Alert>
      ) : (
        <>
          <ReportFilterToolbar
            params={reportParams}
            patch={patch}
            reset={reset}
            activeCount={activeCount}
            options={options.data}
            meta={overviewMeta.data?.meta}
          />
          <OverallExtraFilters
            params={params}
            patch={patch}
            canPickEquipment={hasPermission('assets.view')}
          />

          {/* The ticket headline figures for the chosen period — moved here
              from the administrator's dashboard (owner request, 2026-10-19).
              Same endpoint and filters the toolbar above already uses. */}
          {overviewMeta.data && (
            <section aria-label="Ticket headline figures">
              <TicketOverviewKpis overview={overviewMeta.data} />
            </section>
          )}

          {changedSince && (
            <Alert tone="info" title="New activity since this report loaded">
              <span className="flex flex-col items-start gap-3">
                The figures below are as they were when you opened them.
                <Button variant="secondary" size="sm" onClick={() => void current.refetch()}>
                  Refresh the report
                </Button>
              </span>
            </Alert>
          )}

          <nav
            aria-label="Report sections"
            className="md:sticky md:top-[6.5rem] md:z-20 md:bg-bg md:py-2"
          >
            <label className="flex flex-col gap-2 md:hidden">
              <span className="text-sm font-semibold text-ink">Section</span>
              <Select value={section} onChange={(e) => patch({ section: e.target.value })}>
                {sections.map((s) => (
                  <option key={s.key} value={s.key}>
                    {s.label}
                  </option>
                ))}
              </Select>
            </label>
            <ul className="hidden flex-wrap gap-2 md:flex">
              {sections.map((s) => (
                <li key={s.key}>
                  <button
                    type="button"
                    aria-current={s.key === section ? 'page' : undefined}
                    onClick={() => patch({ section: s.key === 'overview' ? null : s.key })}
                    className={cn(
                      'inline-flex h-12 items-center rounded-md border-2 px-4 text-sm font-semibold transition-colors duration-150',
                      s.key === section
                        ? 'border-primary bg-primary-subtle text-primary-strong'
                        : 'border-border bg-surface text-ink hover:bg-surface-sunken',
                    )}
                  >
                    {s.label}
                  </button>
                </li>
              ))}
            </ul>
          </nav>

          {isInsights ? (
            <InsightsSection params={params} />
          ) : validation ? (
            <Alert tone="warning" title="These filters can't be shown">
              <span className="flex flex-col items-start gap-3">
                {validation}
                <Button variant="secondary" size="sm" onClick={reset}>
                  Reset filters
                </Button>
              </span>
            </Alert>
          ) : status === 403 ? (
            <Alert tone="info" title="This section is not available to you">
              It needs a permission your account does not have. The other sections still work.
            </Alert>
          ) : current.isError && !data ? (
            <ErrorState
              title="This section could not be loaded"
              description="The other sections still work. Try again in a moment."
              onRetry={() => void current.refetch()}
              retrying={current.isFetching}
            />
          ) : !data ? (
            <div className="flex flex-col gap-4" aria-busy="true">
              <Skeleton className="h-10 w-1/3" />
              <Skeleton className="h-28" />
              <Skeleton className="h-80" />
            </div>
          ) : (
            <div
              className={cn('transition-opacity duration-200', current.isFetching && 'opacity-60')}
              aria-busy={current.isFetching}
            >
              <SectionView
                section={data.data}
                meta={data.meta}
                params={params}
                onGroupChange={(group) =>
                  patch({ location_group: group === 'room' ? null : group })
                }
              />
            </div>
          )}
        </>
      )}
    </div>
  )
}

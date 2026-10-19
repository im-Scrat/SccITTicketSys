import { Alert, EmptyState, Skeleton, Surface } from '@/components/ui'
import { useAdminDashboardChannel } from '@/features/dashboard/hooks/useAdminDashboardChannel'
import { LiveStatus } from '@/features/dashboard/components/LiveStatus'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { TechnicianSummaryTable } from '../components/TechnicianSummaryTable'
import { useTechnicianOverview } from '../hooks/queries'

/**
 * Phase 2.9 — technician monitoring (administrators only; the API enforces it).
 *
 * Shows what the system has actually recorded about each technician's work,
 * and what can be calculated from those records. It deliberately shows no
 * availability or "on duty" state: nothing records one.
 */
export default function TechniciansPage() {
  useDocumentMeta({ title: 'Technicians' })
  const overview = useTechnicianOverview()
  const { live } = useAdminDashboardChannel()

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div className="measure">
          <h1 className="text-2xl font-semibold tracking-[-0.02em] text-ink-strong">Technicians</h1>
          <p className="mt-1 text-sm text-muted">
            <strong className="font-semibold text-ink">Recorded</strong> figures are counted from
            assignments and maintenance records.{' '}
            <strong className="font-semibold text-ink">Calculated</strong> figures are medians of
            the time between recorded steps — assigned to accepted, started and completed.
          </p>
        </div>
        <LiveStatus live={live} />
      </header>

      {overview.isError ? (
        <Alert tone="error">
          Technician figures could not be loaded. Refresh the page to retry.
        </Alert>
      ) : overview.isLoading ? (
        <Skeleton className="h-72" />
      ) : (overview.data ?? []).length === 0 ? (
        <EmptyState
          title="No technician accounts yet"
          description="Technicians appear here once their accounts are approved."
        />
      ) : (
        <Surface className="p-2">
          <TechnicianSummaryTable technicians={overview.data ?? []} caption="All technicians" />
        </Surface>
      )}
    </div>
  )
}

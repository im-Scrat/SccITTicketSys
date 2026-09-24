import { ArrowLeft, History } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Alert, Button, EmptyState, Pagination, Skeleton } from '@/components/ui'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { MaintenanceFilters } from '../components/MaintenanceFilters'
import { MaintenanceTable } from '../components/MaintenanceTable'
import { useMaintenanceHistory, useMaintenanceOptions } from '../hooks/queries'
import type { MaintenanceParams } from '../types'

const PER_PAGE = 20

/**
 * A technician's finished maintenance (SRS FR-MNT-011).
 *
 * Permanently readable and permanently read-only. A technician needs their own
 * work history for reference and audit — they are accountable for it — which is
 * why read access outlives the visit even though every write ability lapses with
 * it. Opening a record from here shows the account of the job with no controls.
 */
export default function MaintenanceHistoryPage() {
  useDocumentMeta({ title: 'Maintenance history' })
  const navigate = useNavigate()

  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')

  const options = useMaintenanceOptions()

  const query = useMemo<MaintenanceParams>(
    () => ({
      search: params.get('search') ?? undefined,
      type: params.get('type') ?? undefined,
      preventive: params.get('preventive') ?? undefined,
      sort: params.get('sort') ?? 'completed_at',
      direction: (params.get('direction') as 'asc' | 'desc') ?? 'desc',
      page: params.get('page') ? Number(params.get('page')) : 1,
      per_page: PER_PAGE,
    }),
    [params],
  )

  const history = useMaintenanceHistory(query)

  useEffect(() => {
    const timer = setTimeout(() => {
      if ((params.get('search') ?? '') === search) return
      patch({ search: search || null, page: null })
    }, 300)
    return () => clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search])

  function patch(changes: Record<string, string | number | null>) {
    setParams(
      (previous) => {
        const next = new URLSearchParams(previous)
        for (const [key, value] of Object.entries(changes)) {
          if (value === null || value === '') next.delete(key)
          else next.set(key, String(value))
        }
        return next
      },
      { replace: true },
    )
  }

  function onSort(column: string) {
    const current = params.get('sort') ?? 'completed_at'
    const direction =
      current === column && (params.get('direction') ?? 'desc') === 'desc' ? 'asc' : 'desc'
    patch({ sort: column, direction, page: null })
  }

  const records = history.data?.data ?? []

  return (
    <div className="flex flex-col gap-8">
      <div>
        <Button variant="ghost" size="sm" onClick={() => navigate('/app/maintenance')}>
          <ArrowLeft size={26} aria-hidden="true" />
          My maintenance
        </Button>
      </div>

      <header className="measure">
        <h1 className="text-2xl font-bold text-ink-strong">Maintenance history</h1>
        <p className="mt-2 text-base text-muted">
          Visits you completed or had cancelled. Kept permanently for reference and audit.
        </p>
      </header>

      <MaintenanceFilters
        search={search}
        onSearch={setSearch}
        searchPlaceholder="Search your history…"
        params={params}
        patch={patch}
        options={options.data}
        show={['type', 'preventive']}
        onClear={() => setParams(new URLSearchParams(), { replace: true })}
      />

      {history.isError && (
        <Alert tone="error" title="Could not load your history">
          Try again in a moment.
        </Alert>
      )}

      {history.isLoading ? (
        <div className="flex flex-col gap-2" aria-busy="true" aria-label="Loading history">
          {Array.from({ length: 6 }).map((_, index) => (
            <Skeleton key={index} className="h-16 rounded-lg" />
          ))}
        </div>
      ) : records.length === 0 ? (
        <EmptyState
          icon={<History className="size-6" aria-hidden="true" />}
          title="Nothing finished yet"
          description="Completed and cancelled visits collect here."
        />
      ) : (
        <>
          <MaintenanceTable
            records={records}
            sort={params.get('sort') ?? 'completed_at'}
            direction={(params.get('direction') as 'asc' | 'desc') ?? 'desc'}
            onSort={onSort}
            showTechnician={false}
            onOpen={(id) => navigate(`/app/maintenance/${id}`)}
          />

          {history.data && (
            <Pagination
              page={history.data.meta.current_page}
              lastPage={history.data.meta.last_page}
              total={history.data.meta.total}
              from={history.data.meta.from}
              to={history.data.meta.to}
              onPage={(page) => patch({ page })}
            />
          )}
        </>
      )}
    </div>
  )
}

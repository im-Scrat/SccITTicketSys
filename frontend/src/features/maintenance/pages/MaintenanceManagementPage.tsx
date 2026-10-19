import { ClipboardList } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Alert, EmptyState, Pagination, Skeleton, StatCard } from '@/components/ui'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { MaintenanceFilters } from '../components/MaintenanceFilters'
import { MaintenanceTable } from '../components/MaintenanceTable'
import {
  useMaintenanceDashboard,
  useMaintenanceDirectory,
  useMaintenanceOptions,
} from '../hooks/queries'
import type { MaintenanceParams } from '../types'

const PER_PAGE = 20

/**
 * The Administrator's cross-estate maintenance surface (SRS FR-MNT-011,
 * FR-DSH-003).
 *
 * Role-gated at the route *and* at every API method behind it: `maintenance.view`
 * cannot close this page, because a technician holds it too. The prefix in the
 * URL is a naming convention; `viewAdministrative` is the control.
 *
 * Every tile is a live aggregate over `maintenance_records`, and selecting one
 * filters the table beneath it to exactly that set — so a figure and the rows it
 * claims to count can never disagree.
 */
export default function MaintenanceManagementPage() {
  useDocumentMeta({ title: 'Maintenance management' })
  const navigate = useNavigate()

  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')

  const options = useMaintenanceOptions()
  const dashboard = useMaintenanceDashboard()

  const query = useMemo<MaintenanceParams>(
    () => ({
      search: params.get('search') ?? undefined,
      status: params.get('status') ?? undefined,
      type: params.get('type') ?? undefined,
      preventive: params.get('preventive') ?? undefined,
      technician: params.get('technician') ?? undefined,
      overdue: params.get('overdue') ?? undefined,
      trashed: (params.get('trashed') as MaintenanceParams['trashed']) ?? undefined,
      sort: params.get('sort') ?? 'created_at',
      direction: (params.get('direction') as 'asc' | 'desc') ?? 'desc',
      page: params.get('page') ? Number(params.get('page')) : 1,
      per_page: PER_PAGE,
    }),
    [params],
  )

  const directory = useMaintenanceDirectory(query)

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
    const current = params.get('sort') ?? 'created_at'
    const direction =
      current === column && (params.get('direction') ?? 'desc') === 'desc' ? 'asc' : 'desc'
    patch({ sort: column, direction, page: null })
  }

  const posture = dashboard.data?.posture
  const records = directory.data?.data ?? []

  return (
    <div className="flex flex-col gap-8">
      <header className="measure">
        <h1 className="text-2xl font-bold text-ink-strong">Maintenance management</h1>
        <p className="mt-2 text-base text-muted">
          Every maintenance record across the estate — preventive rounds, corrective repairs, and
          what each of them cost.
        </p>
      </header>

      {dashboard.isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {Array.from({ length: 4 }).map((_, index) => (
            <Skeleton key={index} className="h-28 rounded-lg" />
          ))}
        </div>
      ) : (
        posture && (
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
              label="Overdue"
              value={posture.overdue}
              tone={posture.overdue > 0 ? 'danger' : 'neutral'}
              hint="Past its scheduled date and still open"
              active={params.get('overdue') === '1'}
              onClick={() =>
                patch({ overdue: params.get('overdue') === '1' ? null : '1', page: null })
              }
            />
            <StatCard
              label={`Due in ${posture.lead_days} days`}
              value={posture.due_soon}
              tone={posture.due_soon > 0 ? 'warning' : 'neutral'}
              hint="Inside the configured reminder window"
            />
            <StatCard
              label="In progress"
              value={posture.in_progress}
              tone="info"
              active={params.get('status') === 'in_progress'}
              onClick={() =>
                patch({
                  status: params.get('status') === 'in_progress' ? null : 'in_progress',
                  page: null,
                })
              }
            />
            <StatCard
              label="Scheduled"
              value={posture.scheduled}
              active={params.get('status') === 'scheduled'}
              onClick={() =>
                patch({
                  status: params.get('status') === 'scheduled' ? null : 'scheduled',
                  page: null,
                })
              }
            />
          </div>
        )
      )}

      <MaintenanceFilters
        search={search}
        onSearch={setSearch}
        searchPlaceholder="Search all maintenance…"
        params={params}
        patch={patch}
        options={options.data}
        show={['status', 'type', 'preventive', 'technician', 'overdue', 'trashed']}
        onClear={() => setParams(new URLSearchParams(), { replace: true })}
      />

      {directory.isError && (
        <Alert tone="error" title="Could not load the directory">
          Try again in a moment.
        </Alert>
      )}

      {directory.isLoading ? (
        <div className="flex flex-col gap-2" aria-busy="true" aria-label="Loading maintenance">
          {Array.from({ length: 8 }).map((_, index) => (
            <Skeleton key={index} className="h-16 rounded-lg" />
          ))}
        </div>
      ) : records.length === 0 ? (
        <EmptyState
          icon={<ClipboardList className="size-6" aria-hidden="true" />}
          title="No maintenance matches these filters"
          description="Clear the filters to see the whole estate."
        />
      ) : (
        <>
          <MaintenanceTable
            records={records}
            sort={params.get('sort') ?? 'created_at'}
            direction={(params.get('direction') as 'asc' | 'desc') ?? 'desc'}
            onSort={onSort}
            onOpen={(id) => navigate(`/app/maintenance/${id}`)}
          />

          {directory.data && (
            <Pagination
              page={directory.data.meta.current_page}
              lastPage={directory.data.meta.last_page}
              total={directory.data.meta.total}
              from={directory.data.meta.from}
              to={directory.data.meta.to}
              onPage={(page) => patch({ page })}
            />
          )}
        </>
      )}
    </div>
  )
}

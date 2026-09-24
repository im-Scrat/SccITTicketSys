import { Plus, Wrench } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Alert, Button, EmptyState, Pagination, Skeleton } from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { MaintenanceFilters } from '../components/MaintenanceFilters'
import { MaintenanceTable } from '../components/MaintenanceTable'
import { useMaintenanceOptions, useMaintenanceQueue } from '../hooks/queries'
import type { MaintenanceParams } from '../types'

const PER_PAGE = 20

/**
 * A technician's open maintenance work (SRS FR-MNT-003/011).
 *
 * **This page means the same thing for every role.** It is scoped server-side to
 * records the caller was assigned or opened, so an administrator opening it sees
 * *their own* work, not the estate. "My maintenance" that silently became "all
 * maintenance" for one role would be a page that lies to the other; the estate
 * view is the management surface, which says so in its name.
 *
 * The order is the server's and is deliberately not sortable: overdue first,
 * then soonest due, with undated work last. A queue that can be re-sorted into a
 * comfortable order stops being a queue.
 */
export default function MaintenanceQueuePage() {
  useDocumentMeta({ title: 'Maintenance' })
  const navigate = useNavigate()
  const { hasPermission } = useAuth()
  const canCreate = hasPermission('maintenance.create')

  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')

  const options = useMaintenanceOptions()

  const query = useMemo<MaintenanceParams>(
    () => ({
      search: params.get('search') ?? undefined,
      status: params.get('status') ?? undefined,
      type: params.get('type') ?? undefined,
      preventive: params.get('preventive') ?? undefined,
      overdue: params.get('overdue') ?? undefined,
      page: params.get('page') ? Number(params.get('page')) : 1,
      per_page: PER_PAGE,
    }),
    [params],
  )

  const queue = useMaintenanceQueue(query)

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

  const records = queue.data?.data ?? []

  return (
    <div className="flex flex-col gap-8">
      <header className="flex flex-wrap items-end justify-between gap-6">
        <div className="measure">
          <h1 className="text-2xl font-bold text-ink-strong">My maintenance</h1>
          <p className="mt-2 text-base text-muted">
            Work assigned to you or opened by you, overdue first. Finished visits move to your
            history.
          </p>
        </div>

        {canCreate && (
          <Button onClick={() => navigate('/app/maintenance/new')}>
            <Plus size={32} aria-hidden="true" />
            Schedule maintenance
          </Button>
        )}
      </header>

      <MaintenanceFilters
        search={search}
        onSearch={setSearch}
        searchPlaceholder="Search your maintenance…"
        params={params}
        patch={patch}
        options={options.data}
        show={['status', 'type', 'preventive', 'overdue']}
        onClear={() => setParams(new URLSearchParams(), { replace: true })}
      />

      {queue.isError && (
        <Alert tone="error" title="Could not load your maintenance">
          Try again in a moment. If it keeps failing, the API may be unreachable.
        </Alert>
      )}

      {queue.isLoading ? (
        <div className="flex flex-col gap-2" aria-busy="true" aria-label="Loading maintenance">
          {Array.from({ length: 6 }).map((_, index) => (
            <Skeleton key={index} className="h-16 rounded-lg" />
          ))}
        </div>
      ) : records.length === 0 ? (
        <EmptyState
          icon={<Wrench className="size-6" aria-hidden="true" />}
          title="No open maintenance"
          description="Work assigned to you appears here."
          action={
            canCreate ? (
              <Button variant="secondary" onClick={() => navigate('/app/maintenance/new')}>
                Schedule maintenance
              </Button>
            ) : undefined
          }
        />
      ) : (
        <>
          <MaintenanceTable
            records={records}
            sort="scheduled_for"
            direction="asc"
            onSort={() => undefined}
            sortable={false}
            showTechnician={false}
            onOpen={(id) => navigate(`/app/maintenance/${id}`)}
          />

          {queue.data && (
            <Pagination
              page={queue.data.meta.current_page}
              lastPage={queue.data.meta.last_page}
              total={queue.data.meta.total}
              from={queue.data.meta.from}
              to={queue.data.meta.to}
              onPage={(page) => patch({ page })}
            />
          )}
        </>
      )}
    </div>
  )
}

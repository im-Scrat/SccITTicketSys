import { ArrowLeft, CalendarClock } from 'lucide-react'
import { useMemo } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Alert, Button, EmptyState, Pagination, Skeleton } from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { MaintenanceTable } from '../components/MaintenanceTable'
import { useScheduledMaintenance } from '../hooks/queries'
import type { MaintenanceParams } from '../types'

const PER_PAGE = 20

/**
 * The preventive horizon (SRS FR-MNT-007).
 *
 * Dated open work, soonest first — the in-app expression of the due detection
 * the scheduler runs each morning. Both read the *same* predicate on the server,
 * so this page and `maintenance:detect-due` can never disagree about what is
 * overdue.
 *
 * An administrator can widen it to the whole estate with `scope=all`. A
 * technician passing the same parameter gets their own calendar back rather than
 * a 403: asking is not the same as being entitled, and refusing a read they were
 * allowed to make would be the wrong answer to the wrong question.
 */
export default function ScheduledMaintenancePage() {
  useDocumentMeta({ title: 'Scheduled maintenance' })
  const navigate = useNavigate()
  const { user } = useAuth()

  const [params, setParams] = useSearchParams()
  const isAdministrator = user?.role.slug === 'administrator'
  const scope = params.get('scope') === 'all' ? 'all' : 'own'

  const query = useMemo<MaintenanceParams>(
    () => ({
      scope: scope as 'own' | 'all',
      page: params.get('page') ? Number(params.get('page')) : 1,
      per_page: PER_PAGE,
    }),
    [params, scope],
  )

  const scheduled = useScheduledMaintenance(query)

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

  const records = scheduled.data?.data ?? []

  return (
    <div className="flex flex-col gap-8">
      <div>
        <Button variant="ghost" size="sm" onClick={() => navigate('/app/maintenance')}>
          <ArrowLeft size={26} aria-hidden="true" />
          My maintenance
        </Button>
      </div>

      <header className="flex flex-wrap items-end justify-between gap-6">
        <div className="measure">
          <h1 className="text-2xl font-bold text-ink-strong">Scheduled maintenance</h1>
          <p className="mt-2 text-base text-muted">
            Dated work that has not been completed, soonest first. Anything past its date is marked
            overdue.
          </p>
        </div>

        {isAdministrator && (
          <div className="flex gap-2" role="group" aria-label="Scope">
            <Button
              variant={scope === 'own' ? 'primary' : 'secondary'}
              size="sm"
              onClick={() => patch({ scope: null, page: null })}
            >
              Mine
            </Button>
            <Button
              variant={scope === 'all' ? 'primary' : 'secondary'}
              size="sm"
              onClick={() => patch({ scope: 'all', page: null })}
            >
              Whole estate
            </Button>
          </div>
        )}
      </header>

      {scheduled.isError && (
        <Alert tone="error" title="Could not load the schedule">
          Try again in a moment.
        </Alert>
      )}

      {scheduled.isLoading ? (
        <div className="flex flex-col gap-2" aria-busy="true" aria-label="Loading schedule">
          {Array.from({ length: 5 }).map((_, index) => (
            <Skeleton key={index} className="h-16 rounded-lg" />
          ))}
        </div>
      ) : records.length === 0 ? (
        <EmptyState
          icon={<CalendarClock className="size-6" aria-hidden="true" />}
          title="Nothing scheduled"
          description="Preventive rounds with a date appear here as they are opened."
        />
      ) : (
        <>
          <MaintenanceTable
            records={records}
            sort="scheduled_for"
            direction="asc"
            onSort={() => undefined}
            sortable={false}
            showTechnician={scope === 'all'}
            onOpen={(id) => navigate(`/app/maintenance/${id}`)}
          />

          {scheduled.data && (
            <Pagination
              page={scheduled.data.meta.current_page}
              lastPage={scheduled.data.meta.last_page}
              total={scheduled.data.meta.total}
              from={scheduled.data.meta.from}
              to={scheduled.data.meta.to}
              onPage={(page) => patch({ page })}
            />
          )}
        </>
      )}
    </div>
  )
}

import { ArrowLeft } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Alert, Button, EmptyState, Pagination, SearchInput, Skeleton } from '@/components/ui'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { TicketsTable } from '../components/TicketsTable'
import { useAssignmentHistory } from '../hooks/queries'
import type { TicketParams } from '../types'

const PER_PAGE = 20

/**
 * A technician's finished work — readable permanently, writable never
 * (SDD DD-42).
 *
 * The read/write split is the point of having a separate page. A technician who
 * repaired a machine last term should be able to look up what they did to it,
 * because that is exactly the context the next repair needs — but a ticket they
 * have completed or handed on is no longer theirs to move, and a page that
 * offered the buttons anyway would be inviting a 403.
 *
 * Declined assignments are deliberately **not** here. Refusing a job leaves no
 * work history worth referencing, and keeping the ticket readable afterwards
 * would widen visibility for nothing.
 */
export default function AssignmentHistoryPage() {
  useDocumentMeta({ title: 'Completed work' })
  const navigate = useNavigate()

  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')

  const query = useMemo<TicketParams>(
    () => ({
      search: params.get('search') ?? undefined,
      page: params.get('page') ? Number(params.get('page')) : 1,
      per_page: PER_PAGE,
    }),
    [params],
  )

  const history = useAssignmentHistory(query)

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

  const rows = history.data?.data ?? []

  return (
    <div className="flex flex-col gap-8">
      <div>
        <Button variant="ghost" size="sm" onClick={() => navigate('/app/tickets/assigned')}>
          <ArrowLeft size={26} aria-hidden="true" />
          My work
        </Button>
      </div>

      <header className="measure">
        <h1 className="text-2xl font-bold text-ink-strong">Completed work</h1>
        <p className="mt-2 text-base text-muted">
          Everything you have finished or handed on. Kept so you can look up what was done to a
          machine before — these tickets are read-only.
        </p>
      </header>

      <SearchInput
        value={search}
        onChange={setSearch}
        placeholder="Search your completed work…"
        aria-label="Search your completed work"
      />

      <section className="rounded-lg border-2 border-border bg-surface">
        {history.isError ? (
          <Alert tone="error" title="Your history could not be loaded">
            Refresh the page to try again.
          </Alert>
        ) : history.isLoading ? (
          <div className="flex flex-col gap-3 p-6">
            {Array.from({ length: 6 }).map((_, index) => (
              <Skeleton key={index} className="h-14 rounded-md" />
            ))}
          </div>
        ) : rows.length === 0 ? (
          <EmptyState
            title="No completed work yet"
            description="Tickets you finish or hand on move here, so you can refer back to what was done."
          />
        ) : (
          <>
            <TicketsTable
              tickets={rows}
              sort="updated_at"
              direction="desc"
              onSort={() => undefined}
              onOpen={(id) => navigate(`/app/tickets/assigned/${id}`)}
              showTechnician={false}
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
      </section>
    </div>
  )
}

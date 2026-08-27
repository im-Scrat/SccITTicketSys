import { History } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Alert, Button, EmptyState, Pagination, Skeleton } from '@/components/ui'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { TicketFilters } from '../components/TicketFilters'
import { TicketsTable } from '../components/TicketsTable'
import { useAssignedTickets, useTicketOptions } from '../hooks/queries'
import type { TicketParams } from '../types'

const PER_PAGE = 20

/**
 * A technician's active work queue (SRS FR-ASN-004/006, Figure 4).
 *
 * **This is the whole of a technician's ticket surface.** There is no feed and
 * no directory here, and that is not a matter of route placement: every query
 * behind this page is scoped by `TicketVisibility` to tickets they hold an
 * assignment for, and the policy refuses a guessed uuid for the same reason. A
 * technician holds `tickets.view` like everyone else — which *rows* it reaches
 * is what differs (SDD DD-40).
 *
 * The order is the server's and is not sortable by accident: most severe first,
 * then soonest deadline. A queue that can be re-sorted into a comfortable order
 * stops being a queue.
 */
export default function AssignedTicketsPage() {
  useDocumentMeta({ title: 'My work' })
  const navigate = useNavigate()

  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')

  const options = useTicketOptions()

  const query = useMemo<TicketParams>(
    () => ({
      search: params.get('search') ?? undefined,
      status: params.get('status') ?? undefined,
      priority: params.get('priority') ?? undefined,
      category: params.get('category') ?? undefined,
      page: params.get('page') ? Number(params.get('page')) : 1,
      per_page: PER_PAGE,
    }),
    [params],
  )

  const queue = useAssignedTickets(query)

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

  const rows = queue.data?.data ?? []

  return (
    <div className="flex flex-col gap-8">
      <header className="flex flex-wrap items-end justify-between gap-6">
        <div className="measure">
          <h1 className="text-2xl font-bold text-ink-strong">My work</h1>
          <p className="mt-2 text-base text-muted">
            The tickets assigned to you, most urgent first. Open one to see the fault, the machine
            and where it is — then accept it, start work, or hand it back.
          </p>
        </div>

        <Button variant="secondary" onClick={() => navigate('/app/tickets/history')}>
          <History size={32} aria-hidden="true" />
          Completed work
        </Button>
      </header>

      <TicketFilters
        search={search}
        onSearch={setSearch}
        searchPlaceholder="Search your assigned tickets…"
        params={params}
        patch={patch}
        options={options.data}
        show={['status', 'priority', 'category']}
        onClear={() => {
          setSearch('')
          setParams(new URLSearchParams(), { replace: true })
        }}
      />

      <section className="rounded-lg border-2 border-border bg-surface">
        {queue.isError ? (
          <Alert tone="error" title="Your queue could not be loaded">
            Refresh the page to try again.
          </Alert>
        ) : queue.isLoading ? (
          <TableSkeleton />
        ) : rows.length === 0 ? (
          <EmptyState
            title="Nothing is assigned to you right now"
            description="When an administrator assigns you a ticket it appears here, with the most urgent at the top."
          />
        ) : (
          <>
            <TicketsTable
              tickets={rows}
              sort="priority"
              direction="desc"
              /*
                The queue's order is fixed by the server — severity, then
                deadline — so the headers are inert here rather than pretending
                to sort. Reordering a queue into a comfortable order defeats it.
              */
              onSort={() => undefined}
              onOpen={(id) => navigate(`/app/tickets/assigned/${id}`)}
              showTechnician={false}
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
      </section>
    </div>
  )
}

function TableSkeleton() {
  return (
    <div className="flex flex-col gap-3 p-6">
      {Array.from({ length: 6 }).map((_, index) => (
        <Skeleton key={index} className="h-14 rounded-md" />
      ))}
    </div>
  )
}

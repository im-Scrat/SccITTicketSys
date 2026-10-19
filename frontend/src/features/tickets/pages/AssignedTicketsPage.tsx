import { History } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Alert, Button, EmptyState, Pagination, Skeleton, ViewToggle } from '@/components/ui'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { TicketFilters } from '../components/TicketFilters'
import { TicketsTable } from '../components/TicketsTable'
import {
  ASSIGNED_TICKET_VIEWS,
  ASSIGNED_VIEW_ORDER,
  parseAssignedTicketView,
} from '../assignedTicketViews'
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
 *
 * `?view=` selects one of the views the dashboard's "My work" figures link to (see
 * `assignedTicketViews.ts`). A view is a preset of API filters, so for the Open
 * view it *is* the status filter: that control is not offered, and the URL never
 * carries both.
 */
export default function AssignedTicketsPage() {
  useDocumentMeta({ title: 'My work' })
  const navigate = useNavigate()

  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')

  const options = useTicketOptions()

  // An unrecognised `?view=` is the active queue, not an error: the link may be
  // older than the views this build offers.
  const view = parseAssignedTicketView(params.get('view'))
  const preset = view ? ASSIGNED_TICKET_VIEWS[view] : null

  const query = useMemo<TicketParams>(() => {
    const active = parseAssignedTicketView(params.get('view'))
    const presetParams = active ? ASSIGNED_TICKET_VIEWS[active].params : null

    return {
      search: params.get('search') ?? undefined,
      // A view that carries a status wins over a hand-edited `?status=`, so the
      // request never silently mixes two answers.
      status: presetParams?.status ?? params.get('status') ?? undefined,
      priority: params.get('priority') ?? undefined,
      category: params.get('category') ?? undefined,
      scope: presetParams?.scope,
      due_soon: presetParams?.due_soon,
      breached: presetParams?.breached,
      page: params.get('page') ? Number(params.get('page')) : 1,
      per_page: PER_PAGE,
    }
  }, [params])

  // With the Open view active the Status control is not a second filter, so a
  // stray `?status=` must not show there as though the request were using it.
  const filterParams = useMemo(() => {
    if (!preset?.params.status || !params.has('status')) return params
    const next = new URLSearchParams(params)
    next.delete('status')
    return next
  }, [params, preset])

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
        // The Open view is the status filter; setting either clears the other.
        const chosen = parseAssignedTicketView(
          typeof changes.view === 'string' ? changes.view : null,
        )
        if (chosen && ASSIGNED_TICKET_VIEWS[chosen].params.status) {
          next.delete('status')
        } else if (changes.status) {
          const current = parseAssignedTicketView(next.get('view'))
          if (current && ASSIGNED_TICKET_VIEWS[current].params.status) next.delete('view')
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
            {preset
              ? preset.summary
              : 'The tickets assigned to you, most urgent first. Open one to see the fault, the machine and where it is — then accept it, start work, or hand it back.'}
          </p>
        </div>

        <Button variant="secondary" onClick={() => navigate('/app/tickets/history')}>
          <History size={32} aria-hidden="true" />
          Completed work
        </Button>
      </header>

      <div role="group" aria-label="Ticket view" className="flex flex-wrap gap-2">
        <ViewToggle active={view === null} onClick={() => patch({ view: null, page: null })}>
          Active assignments
        </ViewToggle>
        {ASSIGNED_VIEW_ORDER.map((key) => (
          <ViewToggle
            key={key}
            active={view === key}
            onClick={() => patch({ view: key, page: null })}
          >
            {ASSIGNED_TICKET_VIEWS[key].label}
          </ViewToggle>
        ))}
      </div>

      <TicketFilters
        search={search}
        onSearch={setSearch}
        searchPlaceholder="Search your assigned tickets…"
        params={filterParams}
        patch={patch}
        options={options.data}
        show={preset?.params.status ? ['priority', 'category'] : ['status', 'priority', 'category']}
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
          preset ? (
            <EmptyState
              title={preset.emptyTitle}
              description={preset.emptyDescription}
              action={
                <Button variant="secondary" onClick={() => patch({ view: null, page: null })}>
                  Show my active assignments
                </Button>
              }
            />
          ) : (
            <EmptyState
              title="Nothing is assigned to you right now"
              description="When an administrator assigns you a ticket it appears here, with the most urgent at the top."
            />
          )
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
              hrefFor={(id) => `/app/tickets/assigned/${id}`}
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

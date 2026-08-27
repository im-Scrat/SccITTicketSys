import { ArrowLeft, Plus } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Alert, Button, EmptyState, Pagination, Skeleton } from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { TicketCardItem } from '../components/TicketCardItem'
import { TicketFilters } from '../components/TicketFilters'
import { useMyTickets, useTicketOptions } from '../hooks/queries'
import type { TicketParams } from '../types'

const PER_PAGE = 20

/**
 * "What did I report, and what is happening with it?" (SRS FR-TKT-012).
 *
 * The same card as the feed, because a reporter should recognise their own
 * ticket in the place everyone else sees it — but these are the reporter's own
 * rows, so opening one yields the **full** record rather than the community
 * card. Which projection arrives is the server's decision, made from the
 * caller's relationship to the ticket; this page does not ask for one.
 */
export default function MyTicketsPage() {
  useDocumentMeta({ title: 'My tickets' })
  const navigate = useNavigate()
  const { hasPermission } = useAuth()
  const canCreate = hasPermission('tickets.create')

  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')

  const options = useTicketOptions()

  const query = useMemo<TicketParams>(
    () => ({
      search: params.get('search') ?? undefined,
      status: params.get('status') ?? undefined,
      category: params.get('category') ?? undefined,
      sort: 'created_at',
      direction: 'desc',
      page: params.get('page') ? Number(params.get('page')) : 1,
      per_page: PER_PAGE,
    }),
    [params],
  )

  const tickets = useMyTickets(query)

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

  const rows = tickets.data?.data ?? []

  return (
    <div className="flex flex-col gap-8">
      <div>
        <Button variant="ghost" size="sm" onClick={() => navigate('/app/tickets')}>
          <ArrowLeft size={26} aria-hidden="true" />
          All tickets
        </Button>
      </div>

      <header className="flex flex-wrap items-end justify-between gap-6">
        <div className="measure">
          <h1 className="text-2xl font-bold text-ink-strong">My tickets</h1>
          <p className="mt-2 text-base text-muted">
            Everything you have reported, newest first. Open one to follow its progress, add a
            photo, or confirm that it is fixed.
          </p>
        </div>

        {canCreate && (
          <Button onClick={() => navigate('/app/tickets/new')}>
            <Plus size={32} aria-hidden="true" />
            Report a problem
          </Button>
        )}
      </header>

      <TicketFilters
        search={search}
        onSearch={setSearch}
        searchPlaceholder="Search your tickets…"
        params={params}
        patch={patch}
        options={options.data}
        show={['status', 'category']}
        onClear={() => {
          setSearch('')
          setParams(new URLSearchParams(), { replace: true })
        }}
      />

      {tickets.isError ? (
        <Alert tone="error" title="Your tickets could not be loaded">
          Refresh the page to try again.
        </Alert>
      ) : tickets.isLoading ? (
        <div className="flex flex-col gap-4">
          {Array.from({ length: 3 }).map((_, index) => (
            <Skeleton key={index} className="h-44 rounded-lg" />
          ))}
        </div>
      ) : rows.length === 0 ? (
        <EmptyState
          title="You have not reported anything yet"
          description="When something is not working — a PC, a projector, the network — report it here and you can follow what happens next."
          action={
            canCreate ? (
              <Button onClick={() => navigate('/app/tickets/new')}>
                <Plus size={32} aria-hidden="true" />
                Report a problem
              </Button>
            ) : undefined
          }
        />
      ) : (
        <>
          <ul className="flex flex-col gap-4">
            {rows.map((ticket) => (
              <li key={ticket.id}>
                <TicketCardItem ticket={ticket} />
              </li>
            ))}
          </ul>

          {tickets.data && (
            <Pagination
              page={tickets.data.meta.current_page}
              lastPage={tickets.data.meta.last_page}
              total={tickets.data.meta.total}
              from={tickets.data.meta.from}
              to={tickets.data.meta.to}
              onPage={(page) => patch({ page })}
            />
          )}
        </>
      )}
    </div>
  )
}

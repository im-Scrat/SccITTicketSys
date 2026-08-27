import { Plus } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Navigate, useNavigate, useSearchParams } from 'react-router-dom'
import { Alert, Button, EmptyState, Select, Skeleton } from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { TicketCardItem } from '../components/TicketCardItem'
import { TicketFilters } from '../components/TicketFilters'
import { useTicketFeed, useTicketOptions } from '../hooks/queries'
import type { FeedSort, TicketParams } from '../types'

const PER_PAGE = 20

const SORTS: Array<{ value: FeedSort; label: string }> = [
  { value: 'recent', label: 'Most recent' },
  { value: 'upvotes', label: 'Most upvoted' },
  { value: 'comments', label: 'Most discussed' },
  { value: 'priority', label: 'Highest priority' },
]

/**
 * The community feed — what a teacher sees first (SRS UCS-02, FR-TKT-009/013).
 *
 * Its purpose is duplicate avoidance, not browsing: before reporting a fault you
 * should be able to see that the printer in Lab 3 is already reported and add
 * your weight to it. Every card here is the **restricted projection**, so what
 * one requester learns about another's ticket is a title, an excerpt, where it
 * is and how it is going — never the description in full, the evidence, the
 * technician, or any internal note.
 *
 * **Technicians are not sent here.** Their ticket surface is the assigned queue,
 * and the feed endpoint refuses them outright (403, not an empty list). Rather
 * than showing them a Forbidden page at the destination the nav points to, this
 * route forwards them to their own queue — a navigation convenience only; the
 * API refuses the feed either way.
 *
 * Filter state lives in the URL so a filtered view survives a refresh, a
 * bookmark, the back button and being pasted to a colleague.
 */
export default function TicketFeedPage() {
  useDocumentMeta({ title: 'Tickets' })
  const navigate = useNavigate()
  const { hasPermission, hasRole } = useAuth()
  const canCreate = hasPermission('tickets.create')

  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('search') ?? '')

  const options = useTicketOptions(!hasRole('technician'))

  const query = useMemo<TicketParams>(
    () => ({
      search: params.get('search') ?? undefined,
      status: params.get('status') ?? undefined,
      priority: params.get('priority') ?? undefined,
      category: params.get('category') ?? undefined,
      include_closed: params.get('include_closed') === '1' ? true : undefined,
      sort: (params.get('sort') as FeedSort) ?? 'recent',
      per_page: PER_PAGE,
    }),
    [params],
  )

  const feed = useTicketFeed(query, !hasRole('technician'))

  /** Debounce the search box into the URL. */
  useEffect(() => {
    const timer = setTimeout(() => {
      if ((params.get('search') ?? '') === search) return
      patch({ search: search || null })
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

  if (hasRole('technician')) {
    return <Navigate to="/app/tickets/assigned" replace />
  }

  const tickets = feed.data?.pages.flatMap((page) => page.data) ?? []

  return (
    <div className="flex flex-col gap-8">
      <header className="flex flex-wrap items-end justify-between gap-6">
        <div className="measure">
          <h1 className="text-2xl font-bold text-ink-strong">Tickets</h1>
          <p className="mt-2 text-base text-muted">
            What is currently reported across the school. Before reporting something new, check
            whether it is already here — an upvote tells the IT team how many people it is stopping.
          </p>
        </div>

        <div className="flex flex-wrap gap-3">
          <Button variant="secondary" onClick={() => navigate('/app/tickets/mine')}>
            My tickets
          </Button>
          {canCreate && (
            <Button onClick={() => navigate('/app/tickets/new')}>
              <Plus size={32} aria-hidden="true" />
              Report a problem
            </Button>
          )}
        </div>
      </header>

      <TicketFilters
        search={search}
        onSearch={setSearch}
        searchPlaceholder="Search reported problems…"
        params={params}
        patch={patch}
        options={options.data}
        show={
          (options.data?.priorities.length ?? 0) > 0
            ? ['status', 'priority', 'category', 'include_closed']
            : ['status', 'category', 'include_closed']
        }
        onClear={() => {
          setSearch('')
          setParams(new URLSearchParams(), { replace: true })
        }}
      />

      <div className="flex flex-wrap items-center justify-between gap-4">
        <label className="flex items-center gap-3">
          <span className="text-sm font-semibold text-ink">Sort by</span>
          <Select
            value={(params.get('sort') as FeedSort) ?? 'recent'}
            onChange={(event) => patch({ sort: event.target.value })}
            className="w-auto"
          >
            {SORTS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </label>
      </div>

      {feed.isError ? (
        <Alert tone="error" title="The feed could not be loaded">
          Refresh the page to try again.
        </Alert>
      ) : feed.isLoading ? (
        <div className="flex flex-col gap-4">
          {Array.from({ length: 4 }).map((_, index) => (
            <Skeleton key={index} className="h-44 rounded-lg" />
          ))}
        </div>
      ) : tickets.length === 0 ? (
        <EmptyState
          title="Nothing is reported right now"
          description="When someone reports a problem it appears here, so the rest of the school can see it is known about."
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
            {tickets.map((ticket) => (
              <li key={ticket.id}>
                <TicketCardItem ticket={ticket} />
              </li>
            ))}
          </ul>

          {/*
            An explicit button rather than scroll-triggered loading: infinite
            scroll takes the page end away from anyone navigating by keyboard,
            and hides how much there is left to read.
          */}
          {feed.hasNextPage && (
            <div className="flex justify-center">
              <Button
                variant="secondary"
                onClick={() => void feed.fetchNextPage()}
                loading={feed.isFetchingNextPage}
              >
                Show more tickets
              </Button>
            </div>
          )}
        </>
      )}
    </div>
  )
}

import { Download, Upload } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Alert, Button, EmptyState, Pagination, Skeleton, Tabs } from '@/components/ui'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { TicketMetricsCards } from '../components/TicketMetricsCards'
import { TicketFilters } from '../components/TicketFilters'
import { TicketsTable } from '../components/TicketsTable'
import { useTicketDashboard, useTicketDirectory, useTicketOptions } from '../hooks/queries'
import type { TicketParams, TicketRow, TicketSortColumn } from '../types'

const PER_PAGE = 20

/**
 * The Administrator's ticket oversight surface (SRS FR-TKT-013/014, FR-DSH-003).
 *
 * Administrator-only, and not because of where the route sits: `tickets.view` is
 * held by every role, so the permission cannot close this page. Both the API
 * endpoints behind it and the route guard check
 * `TicketPolicy::viewAdministrative`, which is role-and-permission — a
 * technician who types this url gets the Forbidden page and the calls behind it
 * are refused regardless.
 *
 * Two surfaces on one page, because triage and searching are different jobs.
 * **Triage** is what an administrator opens the page to do: unassigned tickets
 * first, because an unowned fault is the one thing on this screen that nobody is
 * currently doing anything about. **The directory** is for the other question —
 * finding a particular ticket, or every ticket about one room.
 */
export default function TicketManagementPage() {
  useDocumentMeta({ title: 'Ticket management' })
  const navigate = useNavigate()

  const [params, setParams] = useSearchParams()
  const [tab, setTab] = useState(params.get('tab') === 'directory' ? 'directory' : 'triage')
  const [search, setSearch] = useState(params.get('search') ?? '')

  const options = useTicketOptions()
  const dashboard = useTicketDashboard()

  const query = useMemo<TicketParams>(
    () => ({
      search: params.get('search') ?? undefined,
      status: params.get('status') ?? undefined,
      priority: params.get('priority') ?? undefined,
      category: params.get('category') ?? undefined,
      technician: params.get('technician') ?? undefined,
      breached: params.get('breached') === '1' ? true : undefined,
      awaiting_confirmation: params.get('awaiting_confirmation') === '1' ? true : undefined,
      trashed: (params.get('trashed') as TicketParams['trashed']) ?? 'without',
      sort: (params.get('sort') as TicketSortColumn) ?? 'created_at',
      direction: (params.get('direction') as 'asc' | 'desc') ?? 'desc',
      page: params.get('page') ? Number(params.get('page')) : 1,
      per_page: PER_PAGE,
    }),
    [params],
  )

  const directory = useTicketDirectory(query, tab === 'directory')

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

  /** A metric tile applies its filter *and* switches to the list that shows it. */
  const applyMetricFilter = (changes: Record<string, string | number | null>) => {
    patch({ ...changes, tab: 'directory' })
    setTab('directory')
  }

  const onSort = (column: TicketSortColumn) =>
    patch({
      sort: column,
      direction: query.sort === column && query.direction === 'asc' ? 'desc' : 'asc',
      page: null,
    })

  const open = (id: string) => navigate(`/app/tickets/manage/${id}`)
  const rows = directory.data?.data ?? []

  return (
    <div className="flex flex-col gap-8">
      <header className="flex flex-wrap items-end justify-between gap-6">
        <div className="measure">
          <h1 className="text-2xl font-bold text-ink-strong">Ticket management</h1>
          <p className="mt-2 text-base text-muted">
            Everything reported across the school: what is unowned, what is running late, and what
            is waiting on the person who reported it.
          </p>
        </div>

        <div className="flex flex-wrap gap-3">
          {/*
            Import and export land in a later phase. Rendered disabled with an
            explanation rather than hidden: an operator who expects them should
            find out they are coming, not wonder whether they missed a menu.
          */}
          <Button variant="secondary" disabled title="Available in a later phase">
            <Upload size={32} aria-hidden="true" />
            Import
          </Button>
          <Button variant="secondary" disabled title="Available in a later phase">
            <Download size={32} aria-hidden="true" />
            Export
          </Button>
        </div>
      </header>

      {dashboard.isError ? (
        <Alert tone="error" title="The triage figures could not be loaded">
          The list below still works. Refresh the page to try the figures again.
        </Alert>
      ) : dashboard.isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
          {Array.from({ length: 6 }).map((_, index) => (
            <Skeleton key={index} className="h-28 rounded-md" />
          ))}
        </div>
      ) : dashboard.data ? (
        <TicketMetricsCards
          dashboard={dashboard.data}
          onFilter={applyMetricFilter}
          activeParams={params}
        />
      ) : null}

      <Tabs
        tabs={[
          { value: 'triage', label: 'Triage' },
          { value: 'directory', label: 'All tickets' },
        ]}
        value={tab}
        onChange={(value) => {
          setTab(value)
          patch({ tab: value === 'directory' ? 'directory' : null, page: null })
        }}
      />

      {tab === 'triage' ? (
        <div className="flex flex-col gap-10">
          <TriageSection
            title="Waiting to be assigned"
            description="No technician has these yet. Assigning one is the single most useful thing on this page."
            emptyTitle="Everything is assigned"
            emptyDescription="Every open ticket has someone working it."
            rows={dashboard.data?.triage_queue ?? []}
            loading={dashboard.isLoading}
            onOpen={open}
          />

          <TriageSection
            title="Waiting on the reporter"
            description={
              dashboard.data
                ? `Resolved, but not yet confirmed. These close themselves after ${dashboard.data.auto_close_days} days — you can close or reopen one at any point before that.`
                : 'Resolved, but not yet confirmed.'
            }
            emptyTitle="Nothing is waiting for confirmation"
            emptyDescription="Every resolved ticket has been confirmed or closed."
            rows={dashboard.data?.awaiting_confirmation ?? []}
            loading={dashboard.isLoading}
            onOpen={open}
          />

          {dashboard.data && dashboard.data.technician_workload.length > 0 && (
            <section>
              <h2 className="text-base font-bold text-ink-strong">Who is carrying what</h2>
              <ul className="mt-4 flex flex-col divide-y divide-border rounded-lg border-2 border-border bg-surface">
                {dashboard.data.technician_workload.map((person) => (
                  <li key={person.id} className="flex items-center justify-between gap-4 px-6 py-4">
                    <span className="text-base font-medium text-ink">{person.name}</span>
                    <span className="text-base font-semibold tnum text-ink-strong">
                      {person.count} open
                    </span>
                  </li>
                ))}
              </ul>
            </section>
          )}
        </div>
      ) : (
        <>
          <TicketFilters
            search={search}
            onSearch={setSearch}
            searchPlaceholder="Search by title, description or ticket number…"
            params={params}
            patch={patch}
            options={options.data}
            show={[
              'status',
              'priority',
              'category',
              'technician',
              'breached',
              'awaiting_confirmation',
              'trashed',
            ]}
            onClear={() => {
              setSearch('')
              setParams(new URLSearchParams([['tab', 'directory']]), { replace: true })
            }}
          />

          <section className="rounded-lg border-2 border-border bg-surface">
            {directory.isError ? (
              <Alert tone="error" title="The directory could not be loaded">
                Refresh the page to try again.
              </Alert>
            ) : directory.isLoading ? (
              <div className="flex flex-col gap-3 p-6">
                {Array.from({ length: 8 }).map((_, index) => (
                  <Skeleton key={index} className="h-14 rounded-md" />
                ))}
              </div>
            ) : rows.length === 0 ? (
              <EmptyState
                title="No tickets match this view"
                description="Adjust the search or filters above."
              />
            ) : (
              <>
                <TicketsTable
                  tickets={rows}
                  sort={query.sort as TicketSortColumn}
                  direction={query.direction ?? 'desc'}
                  onSort={onSort}
                  onOpen={open}
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
          </section>
        </>
      )}
    </div>
  )
}

function TriageSection({
  title,
  description,
  emptyTitle,
  emptyDescription,
  rows,
  loading,
  onOpen,
}: {
  title: string
  description: string
  emptyTitle: string
  emptyDescription: string
  rows: TicketRow[]
  loading: boolean
  onOpen: (id: string) => void
}) {
  return (
    <section>
      <h2 className="text-base font-bold text-ink-strong">{title}</h2>
      <p className="measure mt-2 text-sm text-muted">{description}</p>

      <div className="mt-4 rounded-lg border-2 border-border bg-surface">
        {loading ? (
          <div className="flex flex-col gap-3 p-6">
            {Array.from({ length: 3 }).map((_, index) => (
              <Skeleton key={index} className="h-14 rounded-md" />
            ))}
          </div>
        ) : rows.length === 0 ? (
          <EmptyState title={emptyTitle} description={emptyDescription} />
        ) : (
          <TicketsTable
            tickets={rows}
            sort="priority"
            direction="desc"
            onSort={() => undefined}
            onOpen={onOpen}
          />
        )}
      </div>
    </section>
  )
}

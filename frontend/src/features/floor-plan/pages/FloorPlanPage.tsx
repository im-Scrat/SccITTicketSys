import { useState } from 'react'
import { CircleDashed, DoorClosed, Plus } from 'lucide-react'
import { Link, useNavigate } from 'react-router-dom'
import { Button, EmptyState, ErrorState, SearchInput, Select, Skeleton } from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { RoomFormDrawer } from '@/features/locations/components/RoomFormDrawer'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { useBuildingLookup } from '@/hooks/useLocationLookup'
import { cn } from '@/lib/cn'
import { PC_STATUS, PC_STATUS_TONE_CLASSES, PC_STATUS_VALUES } from '@/lib/pcStatus'
import { RoomCard } from '../components/RoomCard'
import { useFloorPlanRooms } from '../hooks/queries'
import type { FloorPlanRoomsParams } from '../types'

const PER_PAGE = 24

/**
 * The Floorplan landing page — `/app/floor-plan` (Phase 2.11).
 *
 * Every live room as a card: a thumbnail of its plan, its machines' repair
 * status, how much is placed, and a link into its editor. Totals across the
 * rooms sit above; PCs with no room at all (grey "Unassigned") are counted and
 * linked to the asset list rather than listed here. Rooms are created where
 * rooms live — the Locations module's own form, opened in place — so there is
 * one room form, not two.
 */
export default function FloorPlanPage() {
  useDocumentMeta({ title: 'Floorplan' })
  const navigate = useNavigate()
  const { hasPermission } = useAuth()
  const canCreateRoom = hasPermission('locations.create')

  const [search, setSearch] = useState('')
  const [building, setBuilding] = useState('')
  const [layout, setLayout] = useState<'' | 'set_up' | 'not_set_up'>('')
  const [sort, setSort] = useState<'name' | 'pcs' | 'attention'>('name')
  const [page, setPage] = useState(1)
  const [creating, setCreating] = useState(false)
  const debounced = useDebouncedValue(search.trim(), 250)
  const buildings = useBuildingLookup()

  const params: FloorPlanRoomsParams = {
    search: debounced || undefined,
    building: building || undefined,
    layout: layout || undefined,
    sort,
    page,
    per_page: PER_PAGE,
  }
  const query = useFloorPlanRooms(params)
  const rooms = query.data?.data ?? []
  const meta = query.data?.meta
  const filtered = Boolean(debounced || building || layout)

  const totals = rooms.reduce(
    (acc, room) => {
      acc.rooms++
      if (room.layout) acc.setUp++
      acc.pcs += room.pcs.total
      acc.placed += room.pcs.placed
      for (const s of room.pcs.by_status) acc.status[s.value] = (acc.status[s.value] ?? 0) + s.count
      return acc
    },
    { rooms: 0, setUp: 0, pcs: 0, placed: 0, status: {} as Record<string, number> },
  )

  const reset = (apply: () => void) => {
    apply()
    setPage(1)
  }

  return (
    <div className="flex flex-col gap-8">
      <header className="flex flex-wrap items-end justify-between gap-6">
        <div className="measure">
          <h1 className="text-2xl font-bold text-ink-strong">Floorplan</h1>
          <p className="mt-2 text-base text-muted">
            Every room’s layout and the repair status of the PCs in it. Open a room to see or edit
            where each workstation stands.
          </p>
        </div>
        {canCreateRoom && (
          <Button
            leftIcon={<Plus className="size-5" aria-hidden="true" />}
            onClick={() => setCreating(true)}
          >
            New room
          </Button>
        )}
      </header>

      {query.data && (
        <section aria-label="Across these rooms" className="flex flex-wrap items-stretch gap-3">
          <Summary label="Rooms with a Floorplan" value={`${totals.setUp} of ${totals.rooms}`} />
          <Summary label="PCs placed" value={`${totals.placed} of ${totals.pcs}`} />
          {PC_STATUS_VALUES.map((value) => {
            const Icon = PC_STATUS[value].icon
            const tone = PC_STATUS_TONE_CLASSES[PC_STATUS[value].tone]
            return (
              <Summary
                key={value}
                label={PC_STATUS[value].label}
                value={String(totals.status[value] ?? 0)}
                icon={<Icon className={cn('size-5', tone.text)} aria-hidden="true" />}
                className={tone.border}
              />
            )
          })}
          {meta && meta.unassigned_pcs > 0 && (
            <Link
              to="/app/assets/list?tab=pc-units&unassigned=1"
              className="flex min-w-40 flex-col justify-center gap-1 rounded-md border border-dashed border-border bg-surface px-4 py-3 transition-colors hover:bg-surface-sunken focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
            >
              <span className="flex items-center gap-2 text-sm text-muted">
                <CircleDashed className="size-5" aria-hidden="true" />
                Unassigned PCs
              </span>
              <span className="text-xl font-bold tabular-nums text-ink-strong">
                {meta.unassigned_pcs}
              </span>
              <span className="text-xs text-primary-strong">Not in any room — view in Assets</span>
            </Link>
          )}
        </section>
      )}

      <section aria-labelledby="rooms-heading" className="flex flex-col gap-5">
        <h2 id="rooms-heading" className="sr-only">
          Rooms
        </h2>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_repeat(3,minmax(0,1fr))]">
          <SearchInput
            value={search}
            onChange={(v) => reset(() => setSearch(v))}
            placeholder="Search rooms by name or code"
          />
          <Select
            aria-label="Building"
            value={building}
            onChange={(e) => reset(() => setBuilding(e.target.value))}
          >
            <option value="">Every building</option>
            {(buildings.data ?? []).map((b) => (
              <option key={b.id} value={b.id}>
                {b.name}
              </option>
            ))}
          </Select>
          <Select
            aria-label="Floorplan"
            value={layout}
            onChange={(e) => reset(() => setLayout(e.target.value as typeof layout))}
          >
            <option value="">Set up or not</option>
            <option value="set_up">Has a Floorplan</option>
            <option value="not_set_up">No Floorplan yet</option>
          </Select>
          <Select
            aria-label="Sort"
            value={sort}
            onChange={(e) => reset(() => setSort(e.target.value as typeof sort))}
          >
            <option value="name">Sort by name</option>
            <option value="attention">Needs attention first</option>
            <option value="pcs">Most PCs first</option>
          </Select>
        </div>

        {query.isLoading ? (
          <ul
            className="grid gap-4 grid-cols-[repeat(auto-fill,minmax(17rem,1fr))]"
            aria-busy="true"
            aria-label="Loading rooms"
          >
            {Array.from({ length: 6 }, (_, i) => (
              <li key={i}>
                <Skeleton className="h-80 w-full" />
              </li>
            ))}
          </ul>
        ) : query.isError ? (
          <ErrorState
            title="The rooms could not be loaded"
            description="Check your connection and try again."
            onRetry={() => void query.refetch()}
            retrying={query.isFetching}
          />
        ) : rooms.length === 0 ? (
          filtered ? (
            <EmptyState
              icon={<DoorClosed className="size-6" aria-hidden="true" />}
              title="No rooms match"
              description="Try a different search or clear the filters."
              action={
                <Button
                  variant="secondary"
                  onClick={() =>
                    reset(() => {
                      setSearch('')
                      setBuilding('')
                      setLayout('')
                    })
                  }
                >
                  Clear filters
                </Button>
              }
            />
          ) : (
            <EmptyState
              icon={<DoorClosed className="size-6" aria-hidden="true" />}
              title="No rooms yet"
              description="Rooms are set up in Locations. Once a room exists, its Floorplan starts here."
              action={
                canCreateRoom ? (
                  <Button onClick={() => setCreating(true)}>New room</Button>
                ) : undefined
              }
            />
          )
        ) : (
          <>
            <ul
              className={cn(
                'grid gap-4 grid-cols-[repeat(auto-fill,minmax(17rem,1fr))]',
                query.isFetching && 'opacity-70 transition-opacity',
              )}
              aria-label="Rooms"
            >
              {rooms.map((room) => (
                <RoomCard key={room.id} room={room} />
              ))}
            </ul>
            {meta && meta.last_page > 1 && (
              <nav aria-label="Pages" className="flex items-center justify-between gap-4">
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={page <= 1}
                  onClick={() => setPage((p) => p - 1)}
                >
                  Previous
                </Button>
                <span className="text-sm tabular-nums text-muted">
                  Page {meta.current_page} of {meta.last_page} · {meta.total} rooms
                </span>
                <Button
                  variant="secondary"
                  size="sm"
                  disabled={page >= meta.last_page}
                  onClick={() => setPage((p) => p + 1)}
                >
                  Next
                </Button>
              </nav>
            )}
          </>
        )}
      </section>

      {canCreateRoom && (
        <RoomFormDrawer
          open={creating}
          onClose={() => setCreating(false)}
          onSaved={(room) => navigate(`/app/floor-plan/rooms/${room.id}`)}
        />
      )}
    </div>
  )
}

function Summary({
  label,
  value,
  icon,
  className,
}: {
  label: string
  value: string
  icon?: React.ReactNode
  className?: string
}) {
  return (
    <div
      className={cn(
        'flex min-w-36 flex-col justify-center gap-1 rounded-md border border-border bg-surface px-4 py-3',
        className,
      )}
    >
      <span className="flex items-center gap-2 text-sm text-muted">
        {icon}
        {label}
      </span>
      <span className="text-xl font-bold tabular-nums text-ink-strong">{value}</span>
    </div>
  )
}

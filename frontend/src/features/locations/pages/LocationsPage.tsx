import { Building2, DoorClosed, Plus } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Alert,
  Button,
  EmptyState,
  Pagination,
  SearchInput,
  Select,
  Skeleton,
  Surface,
  Tabs,
} from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { BuildingFormDrawer } from '../components/BuildingFormDrawer'
import { BuildingsTable } from '../components/BuildingsTable'
import { LocationMetricsCards } from '../components/LocationMetricsCards'
import { LocationTree } from '../components/LocationTree'
import { RoomFilters } from '../components/RoomFilters'
import { RoomFormDrawer } from '../components/RoomFormDrawer'
import { RoomsTable } from '../components/RoomsTable'
import {
  useBuildingsList,
  useLocationMetrics,
  useLocationTree,
  useRoomsList,
} from '../hooks/queries'
import type { BuildingParams, BuildingSortColumn, RoomParams, RoomSortColumn } from '../types'

const ROOM_DEFAULTS: RoomParams = {
  sort: 'name',
  direction: 'asc',
  per_page: 20,
  page: 1,
  room_type: 'all',
  active: 'all',
  trashed: 'without',
}

const BUILDING_DEFAULTS: BuildingParams = {
  sort: 'name',
  direction: 'asc',
  per_page: 20,
  page: 1,
  active: 'all',
  trashed: 'without',
}

/**
 * Location Management: the estate at a glance, an explorer for its shape, and a
 * directory for working through it.
 *
 * The tree and the table are one instrument, not two: selecting a building or
 * floor on the left scopes the rooms on the right, so "show me what is on the
 * second floor of Science Hall" is one click rather than a filter form.
 */
export default function LocationsPage() {
  useDocumentMeta({ title: 'Locations' })
  const navigate = useNavigate()
  const { hasPermission } = useAuth()
  const canCreate = hasPermission('locations.create')

  const [tab, setTab] = useState('rooms')
  const [roomParams, setRoomParams] = useState<RoomParams>(ROOM_DEFAULTS)
  const [buildingParams, setBuildingParams] = useState<BuildingParams>(BUILDING_DEFAULTS)
  const [roomSearch, setRoomSearch] = useState('')
  const [buildingSearch, setBuildingSearch] = useState('')
  const [newBuildingOpen, setNewBuildingOpen] = useState(false)
  const [newRoomOpen, setNewRoomOpen] = useState(false)

  const metrics = useLocationMetrics()
  const tree = useLocationTree()
  const rooms = useRoomsList(roomParams, tab === 'rooms')
  const buildings = useBuildingsList(buildingParams)

  // Debounce each search box into its server-side query.
  useEffect(() => {
    const timer = setTimeout(
      () => setRoomParams((prev) => ({ ...prev, search: roomSearch || undefined, page: 1 })),
      300,
    )
    return () => clearTimeout(timer)
  }, [roomSearch])

  useEffect(() => {
    const timer = setTimeout(
      () =>
        setBuildingParams((prev) => ({ ...prev, search: buildingSearch || undefined, page: 1 })),
      300,
    )
    return () => clearTimeout(timer)
  }, [buildingSearch])

  const patchRooms = (patch: Partial<RoomParams>) =>
    setRoomParams((prev) => ({ ...prev, ...patch }))

  const onRoomSort = (column: RoomSortColumn) =>
    patchRooms({
      sort: column,
      direction: roomParams.sort === column && roomParams.direction === 'asc' ? 'desc' : 'asc',
      page: 1,
    })

  const onBuildingSort = (column: BuildingSortColumn) =>
    setBuildingParams((prev) => ({
      ...prev,
      sort: column,
      direction: prev.sort === column && prev.direction === 'asc' ? 'desc' : 'asc',
      page: 1,
    }))

  /** Selecting in the tree scopes the room table and switches to it. */
  const scopeToBuilding = (id: string | undefined) => {
    setTab('rooms')
    patchRooms({ building: id, floor: undefined, page: 1 })
  }

  const scopeToFloor = (buildingId: string, floorId: string | undefined) => {
    setTab('rooms')
    patchRooms({ building: buildingId, floor: floorId, page: 1 })
  }

  const roomRows = rooms.data?.data ?? []
  const roomMeta = rooms.data?.meta
  const buildingRows = buildings.data?.data ?? []
  const buildingMeta = buildings.data?.meta
  const scoped = Boolean(roomParams.building || roomParams.floor)

  return (
    <div className="flex flex-col gap-6">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-[-0.02em] text-ink-strong">Locations</h1>
          <p className="mt-1 text-sm text-muted">
            Buildings, floors and rooms — the spatial model every PC, asset and ticket is placed in.
          </p>
        </div>
        {canCreate && (
          <div className="flex items-center gap-2">
            <Button
              variant="secondary"
              size="sm"
              leftIcon={<Building2 size={15} />}
              onClick={() => setNewBuildingOpen(true)}
            >
              New building
            </Button>
            <Button size="sm" leftIcon={<Plus size={15} />} onClick={() => setNewRoomOpen(true)}>
              New room
            </Button>
          </div>
        )}
      </header>

      {metrics.isLoading ? (
        <Skeleton className="h-24" />
      ) : metrics.data ? (
        <LocationMetricsCards metrics={metrics.data} />
      ) : null}

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[18rem_minmax(0,1fr)] lg:gap-6">
        <Surface className="overflow-hidden lg:sticky lg:top-20 lg:self-start">
          <div className="border-b border-border px-3 py-2.5">
            <h2 className="text-sm font-semibold text-ink-strong">Estate</h2>
            <p className="text-xs text-muted">Select a building or floor to scope the list.</p>
          </div>
          <LocationTree
            nodes={tree.data}
            isLoading={tree.isLoading}
            selected={{ building: roomParams.building, floor: roomParams.floor }}
            onSelectBuilding={scopeToBuilding}
            onSelectFloor={scopeToFloor}
          />
        </Surface>

        <Surface className="overflow-hidden">
          <div className="border-b border-border px-3 pt-2.5">
            <Tabs
              tabs={[
                { value: 'rooms', label: 'Rooms', count: roomMeta?.total },
                { value: 'buildings', label: 'Buildings', count: buildingMeta?.total },
              ]}
              value={tab}
              onChange={setTab}
              className="border-b-0"
            />
          </div>

          {tab === 'rooms' ? (
            <>
              {/* Search on its own line, filters beneath: the panel shares the
                  row with the estate tree, so a single-line toolbar would wrap
                  into a ragged stack at common widths. */}
              <div className="flex flex-col gap-2 border-b border-border p-3">
                <SearchInput
                  value={roomSearch}
                  onChange={setRoomSearch}
                  placeholder="Search room, code or number…"
                  aria-label="Search rooms"
                />
                {/* A grid, not a flex row: `Select` is width-full by design and
                    the project's `cn` does no conflict resolution, so the layout
                    owns the sizing rather than each control fighting it. */}
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                  <RoomFilters params={roomParams} onChange={patchRooms} />
                </div>
              </div>

              {scoped && (
                <div className="flex items-center justify-between gap-3 border-b border-border bg-surface-sunken px-3 py-2">
                  <p className="text-xs text-muted">
                    Scoped to {roomParams.floor ? 'a floor' : 'a building'} from the estate tree.
                  </p>
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => patchRooms({ building: undefined, floor: undefined, page: 1 })}
                  >
                    Clear scope
                  </Button>
                </div>
              )}

              {rooms.isLoading ? (
                <div className="flex flex-col gap-2 p-4">
                  {Array.from({ length: 6 }).map((_, i) => (
                    <Skeleton key={i} className="h-11" />
                  ))}
                </div>
              ) : rooms.isError ? (
                <div className="p-4">
                  <Alert tone="error">We couldn’t load the rooms. Please retry.</Alert>
                </div>
              ) : roomRows.length === 0 ? (
                <EmptyState
                  className="border-0"
                  icon={<DoorClosed size={22} />}
                  title={scoped || roomSearch ? 'No rooms match' : 'No rooms yet'}
                  description={
                    scoped || roomSearch
                      ? 'Adjust the search, filters or scope — or add a room here.'
                      : 'Add a building and a floor first, then the rooms that sit on it.'
                  }
                />
              ) : (
                <>
                  <RoomsTable
                    rooms={roomRows}
                    sort={roomParams.sort ?? 'name'}
                    direction={roomParams.direction ?? 'asc'}
                    onSort={onRoomSort}
                    onRowClick={(id) => navigate(`/app/locations/rooms/${id}`)}
                  />
                  {roomMeta && (
                    <Pagination
                      page={roomMeta.current_page}
                      lastPage={roomMeta.last_page}
                      total={roomMeta.total}
                      from={roomMeta.from}
                      to={roomMeta.to}
                      onPage={(page) => patchRooms({ page })}
                    />
                  )}
                </>
              )}
            </>
          ) : (
            <>
              <div className="flex flex-col gap-2 border-b border-border p-3">
                <SearchInput
                  value={buildingSearch}
                  onChange={setBuildingSearch}
                  placeholder="Search building, code or address…"
                  aria-label="Search buildings"
                />
                <div className="grid grid-cols-1 gap-2 sm:max-w-[16rem]">
                  <Select
                    aria-label="Archived buildings"
                    value={buildingParams.trashed ?? 'without'}
                    onChange={(event) =>
                      setBuildingParams((prev) => ({
                        ...prev,
                        trashed: event.target.value as BuildingParams['trashed'],
                        page: 1,
                      }))
                    }
                  >
                    <option value="without">Hide archived</option>
                    <option value="with">Include archived</option>
                    <option value="only">Archived only</option>
                  </Select>
                </div>
              </div>

              {buildings.isLoading ? (
                <div className="flex flex-col gap-2 p-4">
                  {Array.from({ length: 4 }).map((_, i) => (
                    <Skeleton key={i} className="h-11" />
                  ))}
                </div>
              ) : buildings.isError ? (
                <div className="p-4">
                  <Alert tone="error">We couldn’t load the buildings. Please retry.</Alert>
                </div>
              ) : buildingRows.length === 0 ? (
                <EmptyState
                  className="border-0"
                  icon={<Building2 size={22} />}
                  title={buildingSearch ? 'No buildings match' : 'No buildings yet'}
                  description="A building is the top of the estate — add one, then its floors and rooms."
                />
              ) : (
                <>
                  <BuildingsTable
                    buildings={buildingRows}
                    sort={buildingParams.sort ?? 'name'}
                    direction={buildingParams.direction ?? 'asc'}
                    onSort={onBuildingSort}
                    onRowClick={(id) => navigate(`/app/locations/buildings/${id}`)}
                  />
                  {buildingMeta && (
                    <Pagination
                      page={buildingMeta.current_page}
                      lastPage={buildingMeta.last_page}
                      total={buildingMeta.total}
                      from={buildingMeta.from}
                      to={buildingMeta.to}
                      onPage={(page) => setBuildingParams((prev) => ({ ...prev, page }))}
                    />
                  )}
                </>
              )}
            </>
          )}
        </Surface>
      </div>

      {canCreate && (
        <>
          <BuildingFormDrawer
            open={newBuildingOpen}
            onClose={() => setNewBuildingOpen(false)}
            onSaved={(building) => navigate(`/app/locations/buildings/${building.id}`)}
          />
          <RoomFormDrawer
            open={newRoomOpen}
            onClose={() => setNewRoomOpen(false)}
            defaultBuildingId={roomParams.building}
            defaultFloorId={roomParams.floor}
            onSaved={(room) => navigate(`/app/locations/rooms/${room.id}`)}
          />
        </>
      )}
    </div>
  )
}

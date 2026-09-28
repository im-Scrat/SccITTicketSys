import { ChevronRight, DoorClosed } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Alert, EmptyState, SearchInput, Skeleton } from '@/components/ui'
import { useRoomsList } from '@/features/locations/hooks/queries'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'

/**
 * Entry to the floor plan: pick a room.
 *
 * Rooms come from the existing Locations directory (no second room list to keep
 * in step). That endpoint is Administrator-only in its own right, and the map
 * endpoint behind each link independently refuses anyone else — this page
 * grants nothing.
 */
export default function FloorPlanPage() {
  useDocumentMeta({ title: 'Floor plan' })

  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(search.trim()), 250)
    return () => clearTimeout(timer)
  }, [search])

  const { data, isLoading, isError } = useRoomsList({ search: debounced, per_page: 100 })
  const rooms = data?.data ?? []

  return (
    <div className="flex flex-col gap-6">
      <header>
        <h1 className="text-2xl font-semibold tracking-[-0.02em] text-ink-strong">Floor plan</h1>
        <p className="mt-1 max-w-2xl text-sm text-muted">
          See where each PC unit sits in a room, and its status. Choose a room to open its plan.
        </p>
      </header>

      <SearchInput
        value={search}
        onChange={setSearch}
        placeholder="Search rooms by name or code…"
        aria-label="Search rooms"
        className="max-w-md"
      />

      {isError ? (
        <Alert tone="error">We couldn’t load the rooms.</Alert>
      ) : isLoading ? (
        <div className="flex flex-col gap-2">
          {Array.from({ length: 4 }).map((_, i) => (
            <Skeleton key={i} className="h-16" />
          ))}
        </div>
      ) : rooms.length === 0 ? (
        <EmptyState
          icon={<DoorClosed size={22} />}
          title="No rooms found"
          description={
            debounced
              ? 'No room matches that search. Try a different name or code.'
              : 'Rooms are added under Locations. Once a room exists it appears here.'
          }
        />
      ) : (
        <ul className="divide-y divide-border overflow-hidden rounded-md border border-border bg-surface">
          {rooms.map((room) => (
            <li key={room.id}>
              <Link
                to={`/app/floor-plan/rooms/${room.id}`}
                className="flex items-center justify-between gap-4 px-4 py-4 hover:bg-surface-sunken"
              >
                <span>
                  <span className="block font-medium text-ink-strong">{room.name}</span>
                  <span className="block text-sm text-muted">
                    {[room.building?.name, room.floor?.name].filter(Boolean).join(' · ') ||
                      room.code}
                    {' · '}
                    <span className="tnum">
                      {room.pc_units_count} {room.pc_units_count === 1 ? 'PC unit' : 'PC units'}
                    </span>
                  </span>
                </span>
                <ChevronRight size={18} className="text-faint" aria-hidden="true" />
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

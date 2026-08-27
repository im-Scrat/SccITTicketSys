import { Building2, ChevronRight, Layers } from 'lucide-react'
import { useState } from 'react'
import { EmptyState, Skeleton } from '@/components/ui'
import { cn } from '@/lib/cn'
import type { LocationTreeNode } from '../types'

interface LocationTreeProps {
  nodes: LocationTreeNode[] | undefined
  isLoading: boolean
  /** The currently scoped building/floor, so the tree shows what the table shows. */
  selected: { building?: string; floor?: string }
  onSelectBuilding: (id: string | undefined) => void
  onSelectFloor: (buildingId: string, floorId: string | undefined) => void
}

/**
 * The estate as a disclosure tree: buildings, their floors, and per-level counts.
 *
 * A native nested list with `aria-expanded` buttons rather than a `treeitem`
 * widget — every row is reachable by Tab, Enter/Space toggles, and screen
 * readers announce the structure without a custom key map to learn. Selecting a
 * node scopes the room table beside it; selecting it again clears the scope.
 *
 * Rooms are not in the payload by design: the explorer asks for them per floor
 * (`?floor=<uuid>`), so a large estate never arrives as one response.
 */
export function LocationTree({
  nodes,
  isLoading,
  selected,
  onSelectBuilding,
  onSelectFloor,
}: LocationTreeProps) {
  const [expanded, setExpanded] = useState<Set<string>>(new Set())

  const toggle = (id: string) =>
    setExpanded((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })

  if (isLoading) {
    return (
      <div className="flex flex-col gap-2 p-3">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-9" />
        ))}
      </div>
    )
  }

  if (!nodes || nodes.length === 0) {
    return (
      <EmptyState
        className="border-0"
        icon={<Building2 size={22} />}
        title="No buildings yet"
        description="Add a building, then its floors and rooms. Everything else in the platform is placed inside them."
      />
    )
  }

  return (
    <ul className="flex flex-col gap-0.5 p-2">
      {nodes.map((building) => {
        const isOpen = expanded.has(building.id)
        const isSelected = selected.building === building.id && !selected.floor

        return (
          <li key={building.id}>
            <div className="flex items-center gap-0.5">
              <button
                type="button"
                aria-expanded={isOpen}
                aria-label={`${isOpen ? 'Collapse' : 'Expand'} ${building.name}`}
                onClick={() => toggle(building.id)}
                className="rounded-sm p-1 text-muted hover:bg-surface-sunken hover:text-ink"
              >
                <ChevronRight
                  size={14}
                  aria-hidden="true"
                  className={cn(
                    'transition-transform motion-reduce:transition-none',
                    isOpen && 'rotate-90',
                  )}
                />
              </button>

              <button
                type="button"
                aria-pressed={isSelected}
                onClick={() => onSelectBuilding(isSelected ? undefined : building.id)}
                className={cn(
                  'flex min-w-0 flex-1 items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm',
                  isSelected
                    ? 'bg-primary-subtle font-medium text-primary-strong'
                    : 'text-ink hover:bg-surface-sunken',
                )}
              >
                <Building2 size={15} aria-hidden="true" className="shrink-0" />
                <span className="truncate">{building.name}</span>
                {!building.is_active && (
                  <span className="shrink-0 text-xs text-muted">(inactive)</span>
                )}
                <span className="ml-auto shrink-0 text-xs text-muted tnum">
                  {building.rooms_count}
                </span>
              </button>
            </div>

            {isOpen && (
              <ul className="ml-6 flex flex-col gap-0.5 border-l border-border pl-2">
                {building.floors.length === 0 && (
                  <li className="px-2 py-1.5 text-xs text-muted">No floors yet.</li>
                )}
                {building.floors.map((floor) => {
                  const floorSelected = selected.floor === floor.id

                  return (
                    <li key={floor.id}>
                      <button
                        type="button"
                        aria-pressed={floorSelected}
                        onClick={() =>
                          onSelectFloor(building.id, floorSelected ? undefined : floor.id)
                        }
                        className={cn(
                          'flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm',
                          floorSelected
                            ? 'bg-primary-subtle font-medium text-primary-strong'
                            : 'text-ink hover:bg-surface-sunken',
                        )}
                      >
                        <Layers size={14} aria-hidden="true" className="shrink-0 text-muted" />
                        <span className="truncate">{floor.name}</span>
                        <span className="ml-auto shrink-0 text-xs text-muted tnum">
                          {floor.rooms_count}
                        </span>
                      </button>
                    </li>
                  )
                })}
              </ul>
            )}
          </li>
        )
      })}
    </ul>
  )
}

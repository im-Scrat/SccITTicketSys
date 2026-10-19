import { Building2, DoorClosed, Layers, Monitor } from 'lucide-react'
import { StatCard } from '@/components/ui'
import type { LocationMetrics } from '../types'

interface LocationMetricsCardsProps {
  metrics: LocationMetrics
  /** Open the buildings list on this page. */
  onShowBuildings: () => void
  /** Open the rooms list on this page, unscoped. */
  onShowRooms: () => void
  /** Where floors are seen — the floor plan, for those who may open it. */
  floorsTo?: string
  /** The PC list filtered to PCs with no room, for those who may see assets. */
  unassignedTo?: string
}

/**
 * The estate at a glance. Four figures, chosen because each one answers a
 * question an administrator actually asks: how much is modelled, how much of it
 * is in service, and how much equipment is not placed anywhere yet.
 *
 * Every card opens the list it counts (owner request, 2026-10-19). Floors have
 * no list of their own: they open the floor plan for an administrator, and the
 * buildings list — where each building's floors are — for anyone else. A card
 * whose destination the reader may not open falls back the same way rather
 * than linking to a Forbidden page.
 */
export function LocationMetricsCards({
  metrics,
  onShowBuildings,
  onShowRooms,
  floorsTo,
  unassignedTo,
}: LocationMetricsCardsProps) {
  const { summary, occupancy } = metrics
  const unplaced = occupancy.pc_units_unplaced

  return (
    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
      <StatCard
        label="Buildings"
        value={summary.buildings}
        icon={<Building2 size={15} />}
        hint={
          summary.buildings_inactive > 0
            ? `${summary.buildings_active} active · ${summary.buildings_inactive} inactive`
            : 'All in service'
        }
        onClick={onShowBuildings}
      />
      <StatCard
        label="Floors"
        value={summary.floors}
        icon={<Layers size={15} />}
        hint={floorsTo ? 'Open the floor plan' : undefined}
        to={floorsTo}
        onClick={floorsTo ? undefined : onShowBuildings}
      />
      <StatCard
        label="Rooms"
        value={summary.rooms}
        icon={<DoorClosed size={15} />}
        hint={
          summary.rooms_inactive > 0
            ? `${summary.rooms_active} active · ${summary.rooms_inactive} inactive`
            : `Seats ${summary.total_capacity}`
        }
        onClick={onShowRooms}
      />
      <StatCard
        label="Unassigned PCs"
        value={unplaced}
        icon={<Monitor size={15} />}
        tone={unplaced > 0 ? 'warning' : 'neutral'}
        hint={unplaced > 0 ? 'Not assigned to a room' : 'Every PC has a room'}
        to={unassignedTo}
      />
    </div>
  )
}

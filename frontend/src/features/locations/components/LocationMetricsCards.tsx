import { Building2, DoorClosed, Layers, Monitor } from 'lucide-react'
import { StatCard } from '@/components/ui'
import type { LocationMetrics } from '../types'

/**
 * The estate at a glance. Four figures, chosen because each one answers a
 * question an administrator actually asks: how much is modelled, how much of it
 * is in service, and how much equipment is not placed anywhere yet.
 */
export function LocationMetricsCards({ metrics }: { metrics: LocationMetrics }) {
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
      />
      <StatCard label="Floors" value={summary.floors} icon={<Layers size={15} />} />
      <StatCard
        label="Rooms"
        value={summary.rooms}
        icon={<DoorClosed size={15} />}
        hint={
          summary.rooms_inactive > 0
            ? `${summary.rooms_active} active · ${summary.rooms_inactive} inactive`
            : `Seats ${summary.total_capacity}`
        }
      />
      <StatCard
        label="Unplaced PCs"
        value={unplaced}
        icon={<Monitor size={15} />}
        tone={unplaced > 0 ? 'warning' : 'neutral'}
        hint={unplaced > 0 ? 'Not assigned to a room' : 'Every PC has a room'}
      />
    </div>
  )
}

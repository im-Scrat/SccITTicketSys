import { useId } from 'react'
import type { PcStatus, PlacedPc } from '../types'
import { PcStatusBadge } from './PcStatusBadge'
import { PcTile } from './PcTile'

interface Entry {
  status: PcStatus
  count: number
}

/**
 * Every status present on the map, as a swatch (the very tile the map draws),
 * the server's label as a pill, and a count — in the map card's footer, like
 * the product visual. Nothing is listed that is not on the plan; a legend for
 * absent states is noise.
 */
export function FloorPlanLegend({ pcs }: { pcs: PlacedPc[] }) {
  const headingId = useId()

  const entries = [
    ...pcs
      .reduce((byStatus, pc) => {
        const found = byStatus.get(pc.status.value)
        byStatus.set(pc.status.value, { status: pc.status, count: (found?.count ?? 0) + 1 })
        return byStatus
      }, new Map<string, Entry>())
      .values(),
  ].sort((a, b) => a.status.label.localeCompare(b.status.label))

  if (entries.length === 0) return null

  return (
    <section aria-labelledby={headingId} className="flex flex-wrap items-center gap-x-5 gap-y-2.5">
      <h2 id={headingId} className="text-xs font-semibold uppercase tracking-wide text-muted">
        Legend
      </h2>
      <ul className="flex flex-wrap items-center gap-x-5 gap-y-2.5">
        {entries.map(({ status, count }) => (
          <li key={status.value} className="flex items-center gap-2">
            <svg viewBox="-14 -14 28 28" width="24" height="24" aria-hidden="true">
              <PcTile status={status.value} tone={status.tone} radius={12} />
            </svg>
            <PcStatusBadge status={status} />
            <span className="tnum text-sm text-muted">
              {count} {count === 1 ? 'unit' : 'units'}
            </span>
          </li>
        ))}
      </ul>
    </section>
  )
}

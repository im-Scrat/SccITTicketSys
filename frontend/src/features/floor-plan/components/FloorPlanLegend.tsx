import { useId } from 'react'
import type { PcStatus, PlacedPc } from '../types'
import { PcMarker } from './PcMarker'
import { PcStatusBadge } from './PcStatusBadge'

interface Entry {
  status: PcStatus
  count: number
}

/**
 * Which statuses appear on this map, each with the shape that draws it, its
 * written label, and how many units carry it.
 *
 * Built from the units actually on the plan, so the wording is the server's and
 * there is no second list of statuses here to fall out of step with the
 * backend enum. The shape swatch is decorative; the label is the content.
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
    <section aria-labelledby={headingId} className="rounded-md border border-border bg-surface p-4">
      <h2 id={headingId} className="text-sm font-semibold text-ink-strong">
        Legend
      </h2>
      <ul className="mt-3 flex flex-wrap gap-x-6 gap-y-3">
        {entries.map(({ status, count }) => (
          <li key={status.value} className="flex items-center gap-2.5">
            <svg viewBox="-14 -14 28 28" width="28" height="28" aria-hidden="true">
              <PcMarker status={status.value} tone={status.tone} radius={11} />
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

import { MapPinPlus } from 'lucide-react'
import { Button } from '@/components/ui'
import type { UnplacedPc } from '../types'
import { PcStatusBadge } from './PcStatusBadge'

/**
 * Units that are in the room but not on its plan yet, each with a one-press
 * "Place on plan" that puts it on a free grid point. From there it moves like
 * any other unit. The numeric form offers the same units with exact
 * coordinates.
 */
export function UnplacedUnits({
  units,
  onPlace,
  placingId,
}: {
  units: UnplacedPc[]
  onPlace: (unit: UnplacedPc) => void
  placingId: string | null
}) {
  if (units.length === 0) return null

  return (
    <section
      aria-labelledby="fp-unplaced-heading"
      className="rounded-md border border-border bg-surface p-4 sm:p-5"
    >
      <h2 id="fp-unplaced-heading" className="text-base font-semibold text-ink-strong">
        Not on the plan yet <span className="tnum font-normal text-muted">({units.length})</span>
      </h2>
      <p className="mt-1 text-sm text-muted">
        These units are in the room but have no position on this layout.
      </p>

      <ul className="mt-3 divide-y divide-border">
        {units.map((unit) => (
          <li key={unit.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
            <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
              <span className="font-medium text-ink-strong">{unit.name}</span>
              <span className="slashed-zero font-mono text-xs text-muted">{unit.unit_code}</span>
              <PcStatusBadge status={unit.status} />
            </span>
            <Button
              variant="secondary"
              size="sm"
              leftIcon={<MapPinPlus size={18} aria-hidden="true" />}
              onClick={() => onPlace(unit)}
              loading={placingId === unit.id}
              aria-label={`Place ${unit.name} on the plan`}
            >
              Place on plan
            </Button>
          </li>
        ))}
      </ul>
    </section>
  )
}

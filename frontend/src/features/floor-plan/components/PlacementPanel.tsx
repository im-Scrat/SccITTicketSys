import { Move } from 'lucide-react'
import { useEffect, useState, type FormEvent } from 'react'
import { Button, Checkbox, Field, Input, Select } from '@/components/ui'
import { placementFieldErrors } from '../lib/errors'
import { describePoint, freeSpot, isWithinBounds, nodeMetrics, type Point } from '../lib/placement'
import type { PlacedPc, PlanLayout, UnplacedPc } from '../types'

/**
 * Exact placement by numbers — the third, pointer-free and map-free route to a
 * position (FR-FP-009, WCAG 2.5.7), beside dragging and keyboard move mode.
 *
 * Works for every unit of the room: a placed one is moved, an unplaced one is
 * placed. The fields start at the unit's stored point (or a free spot for an
 * unplaced unit); the server still snaps (when asked), clamps and has the last
 * word, and the map shows what it stored.
 *
 * The bounds check here is a convenience that saves a round trip; the server
 * repeats it against the layout it actually holds.
 */
interface PlacementPanelProps {
  layout: PlanLayout
  pcs: PlacedPc[]
  unplaced: UnplacedPc[]
  selectedId: string | null
  onSelect: (pcId: string) => void
  snapDefault: boolean
  /** Resolves once the server has stored the point; rejects with the server's error. */
  onPlace: (pcId: string, point: Point, snap: boolean) => Promise<unknown>
}

export function PlacementPanel({
  layout,
  pcs,
  unplaced,
  selectedId,
  onSelect,
  snapDefault,
  onPlace,
}: PlacementPanelProps) {
  const placed = pcs.find((pc) => pc.id === selectedId)
  const waiting = unplaced.find((pc) => pc.id === selectedId)
  const unit = placed ?? waiting

  const [x, setX] = useState('')
  const [y, setY] = useState('')
  const [snap, setSnap] = useState(snapDefault)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [submitting, setSubmitting] = useState(false)

  // Start from where the unit is (or could go). Re-run when the stored point
  // changes too — a drag or another administrator's move — so the form never
  // offers a stale "current" position.
  const startX = placed?.x
  const startY = placed?.y
  useEffect(() => {
    const start: Point | null =
      startX !== undefined && startY !== undefined
        ? { x: startX, y: startY }
        : waiting
          ? (freeSpot(pcs, layout, nodeMetrics(layout).radius * 3) ?? {
              x: Math.round(layout.width / 2),
              y: Math.round(layout.height / 2),
            })
          : null

    setX(start ? String(start.x) : '')
    setY(start ? String(start.y) : '')
    setErrors({})
    // `pcs` is deliberately not a dependency: a free spot is chosen once per
    // selection, not re-chosen on every unrelated move.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedId, startX, startY, layout.width, layout.height])

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    if (!unit) return

    const point = { x: Number(x), y: Number(y) }
    const local: Record<string, string> = {}
    if (x.trim() === '' || !Number.isFinite(point.x) || point.x < 0 || point.x > layout.width) {
      local.x = `Enter an x position between 0 and ${layout.width}.`
    }
    if (y.trim() === '' || !Number.isFinite(point.y) || point.y < 0 || point.y > layout.height) {
      local.y = `Enter a y position between 0 and ${layout.height}.`
    }
    setErrors(local)
    if (Object.keys(local).length > 0 || !isWithinBounds(point, layout)) return

    setSubmitting(true)
    try {
      await onPlace(unit.id, point, snap)
    } catch (error) {
      setErrors(placementFieldErrors(error))
    } finally {
      setSubmitting(false)
    }
  }

  const noUnits = pcs.length === 0 && unplaced.length === 0

  return (
    <section
      aria-labelledby="fp-placement-heading"
      className="rounded-md border border-border bg-surface p-4 sm:p-5"
    >
      <h2 id="fp-placement-heading" className="text-base font-semibold text-ink-strong">
        Place a unit by position
      </h2>
      <p className="mt-1 text-sm text-muted">
        Coordinates are in layout pixels from the top-left corner: x from 0 to{' '}
        <span className="tnum">{layout.width}</span>, y from 0 to{' '}
        <span className="tnum">{layout.height}</span>.
      </p>

      {noUnits ? (
        <p className="mt-4 text-sm text-muted">This room has no units to place.</p>
      ) : (
        <form
          onSubmit={submit}
          noValidate
          className="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-4"
        >
          <div className="md:col-span-2 xl:col-span-1">
            <Field label="Unit">
              <Select value={selectedId ?? ''} onChange={(event) => onSelect(event.target.value)}>
                <option value="" disabled>
                  Choose a unit…
                </option>
                {pcs.length > 0 && (
                  <optgroup label="On the plan">
                    {pcs.map((pc) => (
                      <option key={pc.id} value={pc.id}>
                        {pc.name} — at {describePoint(pc)}
                      </option>
                    ))}
                  </optgroup>
                )}
                {unplaced.length > 0 && (
                  <optgroup label="Not placed yet">
                    {unplaced.map((pc) => (
                      <option key={pc.id} value={pc.id}>
                        {pc.name} — not placed
                      </option>
                    ))}
                  </optgroup>
                )}
              </Select>
            </Field>
          </div>

          <Field label="X position" error={errors.x}>
            <Input
              type="number"
              inputMode="decimal"
              step="any"
              min={0}
              max={layout.width}
              value={x}
              onChange={(event) => setX(event.target.value)}
              disabled={!unit}
            />
          </Field>

          <Field label="Y position" error={errors.y}>
            <Input
              type="number"
              inputMode="decimal"
              step="any"
              min={0}
              max={layout.height}
              value={y}
              onChange={(event) => setY(event.target.value)}
              disabled={!unit}
            />
          </Field>

          <div className="flex flex-col justify-end gap-3">
            <Checkbox
              label={`Snap to the ${layout.grid_size} px grid`}
              checked={snap}
              onChange={(event) => setSnap(event.target.checked)}
            />
            <Button
              type="submit"
              leftIcon={<Move size={18} aria-hidden="true" />}
              disabled={!unit}
              loading={submitting}
            >
              {waiting ? 'Place unit' : 'Move unit'}
            </Button>
          </div>
        </form>
      )}
    </section>
  )
}

import { useEffect, useState } from 'react'
import { Alert, Button, Field, Input, Modal } from '@/components/ui'
import { FIXTURES } from '../lib/fixtures'
import type { LayoutSettings, MergeConflict, MergeResult, ObjectKey } from '../lib/layoutDocument'

/* ── Room size and grid ─────────────────────────────────────────────────── */

export function LayoutSettingsDialog({
  open,
  settings,
  onClose,
  onApply,
}: {
  open: boolean
  settings: LayoutSettings
  onClose: () => void
  /** Returns an error message when the new size would leave something outside the room. */
  onApply: (next: LayoutSettings) => string | null
}) {
  const [width, setWidth] = useState(String(settings.width))
  const [height, setHeight] = useState(String(settings.height))
  const [grid, setGrid] = useState(String(settings.grid_size))
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!open) return
    setWidth(String(settings.width))
    setHeight(String(settings.height))
    setGrid(String(settings.grid_size))
    setError(null)
  }, [open, settings])

  const submit = () => {
    const next = { width: Number(width), height: Number(height), grid_size: Number(grid) }
    if (![next.width, next.height].every((v) => Number.isInteger(v) && v >= 100 && v <= 20000)) {
      setError('Width and depth must be whole numbers between 100 and 20,000.')
      return
    }
    if (!Number.isInteger(next.grid_size) || next.grid_size < 5 || next.grid_size > 200) {
      setError('The grid must be a whole number between 5 and 200.')
      return
    }
    const refused = onApply(next)
    if (refused) setError(refused)
    else onClose()
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Room size and grid"
      description="In layout units. A workstation is 40 units square — two cells of the default 20-unit grid."
      footer={
        <div className="flex justify-end gap-3">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button onClick={submit}>Apply</Button>
        </div>
      }
    >
      <form
        className="flex flex-col gap-4"
        onSubmit={(e) => {
          e.preventDefault()
          submit()
        }}
      >
        {error && <Alert tone="error">{error}</Alert>}
        <div className="grid grid-cols-2 gap-4">
          <Field label="Width">
            <Input
              type="number"
              inputMode="numeric"
              value={width}
              onChange={(e) => setWidth(e.target.value)}
            />
          </Field>
          <Field label="Depth">
            <Input
              type="number"
              inputMode="numeric"
              value={height}
              onChange={(e) => setHeight(e.target.value)}
            />
          </Field>
        </div>
        <Field label="Grid size" hint="Snapping and the grid lines both follow it.">
          <Input
            type="number"
            inputMode="numeric"
            value={grid}
            onChange={(e) => setGrid(e.target.value)}
          />
        </Field>
        <button type="submit" hidden />
      </form>
    </Modal>
  )
}

/* ── Concurrent saves ───────────────────────────────────────────────────── */

export function ConflictDialog({
  open,
  merge,
  by,
  describe,
  onCancel,
  onResolve,
  saving,
}: {
  open: boolean
  merge: MergeResult | null
  by: string | null
  describe: (key: ObjectKey) => string
  onCancel: () => void
  onResolve: (choices: Partial<Record<ObjectKey, 'mine' | 'theirs'>>) => void
  saving: boolean
}) {
  const [choices, setChoices] = useState<Partial<Record<ObjectKey, 'mine' | 'theirs'>>>({})
  useEffect(() => setChoices({}), [merge])

  if (!merge) return null
  const who = by ?? 'Another administrator'

  return (
    <Modal
      open={open}
      onClose={onCancel}
      size="lg"
      title="This Floorplan changed while you were editing"
      description={`${who} saved it after you opened it. Nothing has been overwritten.`}
      footer={
        <div className="flex flex-wrap justify-end gap-3">
          <Button variant="secondary" onClick={onCancel}>
            Keep editing
          </Button>
          <Button loading={saving} onClick={() => onResolve(choices)}>
            {merge.conflicts.length > 0 ? 'Combine and save' : 'Save combined layout'}
          </Button>
        </div>
      }
    >
      <div className="flex flex-col gap-4">
        <p className="text-base text-ink">
          {merge.applied === 0
            ? 'None of your changes could be carried over automatically.'
            : `${merge.applied} of your changes apply cleanly on top of theirs.`}
          {merge.conflicts.length === 0 && ' Nothing clashes.'}
        </p>
        {merge.conflicts.length > 0 && (
          <fieldset className="flex flex-col gap-3">
            <legend className="mb-2 text-sm font-semibold text-ink-strong">
              You both changed{' '}
              {merge.conflicts.length === 1 ? 'this' : `these ${merge.conflicts.length}`} — choose
              which to keep
            </legend>
            {merge.conflicts.map((conflict: MergeConflict) => {
              const choice = choices[conflict.key] ?? 'theirs'
              return (
                <div
                  key={conflict.key}
                  className="flex flex-col gap-2 rounded-md border border-border p-4"
                >
                  <span className="text-sm font-semibold text-ink">{describe(conflict.key)}</span>
                  <div className="flex flex-wrap gap-4">
                    {(['theirs', 'mine'] as const).map((side) => (
                      <label key={side} className="flex items-center gap-2 text-sm text-ink">
                        <input
                          type="radio"
                          className="size-5 accent-primary"
                          name={`conflict-${conflict.key}`}
                          checked={choice === side}
                          onChange={() => setChoices((c) => ({ ...c, [conflict.key]: side }))}
                        />
                        {side === 'theirs' ? `Keep ${who}'s version` : 'Keep mine'}
                        <span className="text-muted">
                          — {summarise(side === 'theirs' ? conflict.theirs : conflict.mine)}
                        </span>
                      </label>
                    ))}
                  </div>
                </div>
              )
            })}
          </fieldset>
        )}
      </div>
    </Modal>
  )
}

function summarise(value: MergeConflict['mine']): string {
  if (value === undefined) return 'removed'
  if ('grid_size' in value) return `${value.width} × ${value.height}, grid ${value.grid_size}`
  if ('kind' in value)
    return `${FIXTURES[value.kind].label} at ${Math.round(value.x)}, ${Math.round(value.y)}`
  return `at ${Math.round(value.x)}, ${Math.round(value.y)}${value.rotation ? `, turned ${value.rotation}°` : ''}`
}

/* ── Keyboard shortcuts ─────────────────────────────────────────────────── */

const SHORTCUTS: [string, string][] = [
  ['Tab', 'Move between workstations'],
  ['Enter', 'Show the selected workstation’s details'],
  [
    'Arrow keys',
    'Move the selection one grid cell (Shift: one unit) — or pan when nothing is selected',
  ],
  ['R / Shift+R', 'Turn the selection clockwise / anticlockwise'],
  ['Delete', 'Remove the selection from the plan'],
  ['Ctrl+D', 'Duplicate the selected fixture'],
  ['Ctrl+Z / Ctrl+Shift+Z', 'Undo / redo'],
  ['Ctrl+S', 'Save'],
  ['+ / −', 'Zoom in / out (or Ctrl + scroll)'],
  ['F', 'Fit the room'],
  ['G / S', 'Show the grid / snap to it'],
  ['Alt while dragging', 'Place freely, off the grid'],
  ['Escape', 'Clear the selection'],
]

export function ShortcutsDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Keyboard shortcuts"
      description="The canvas works without a mouse."
    >
      <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-3 text-sm">
        {SHORTCUTS.map(([keys, what]) => (
          <div key={keys} className="contents">
            <dt>
              <kbd className="rounded border border-border bg-surface-sunken px-2 py-0.5 font-mono text-xs text-ink">
                {keys}
              </kbd>
            </dt>
            <dd className="text-ink">{what}</dd>
          </div>
        ))}
      </dl>
    </Modal>
  )
}

/* ── Auto-arrange ───────────────────────────────────────────────────────── */

export function AutoArrangeDialog({
  open,
  count,
  noun = 'PC',
  onClose,
  onArrange,
}: {
  open: boolean
  count: number
  /** "PC", or "item" once confirmed equipment (DD-115) is waiting too. */
  noun?: string
  onClose: () => void
  /** Returns how many fitted. */
  onArrange: (gap: number) => number
}) {
  const [gap, setGap] = useState('20')
  const [result, setResult] = useState<string | null>(null)
  useEffect(() => {
    if (open) setResult(null)
  }, [open])

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={`Arrange ${count} unplaced ${noun}${count === 1 ? '' : 's'}`}
      description="They are laid out in rows from the top-left, skipping every spot already taken. You can move any of them afterwards, or undo."
      footer={
        <div className="flex justify-end gap-3">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button
            onClick={() => {
              const placed = onArrange(Math.max(0, Number(gap) || 0))
              if (placed === count) onClose()
              else
                setResult(
                  `${placed} of ${count} fitted. Make the room larger or the spacing smaller for the rest.`,
                )
            }}
          >
            Arrange
          </Button>
        </div>
      }
    >
      <div className="flex flex-col gap-4">
        {result && <Alert tone="warning">{result}</Alert>}
        <Field label="Space between workstations" hint="In layout units; 20 is one grid cell.">
          <Input
            type="number"
            inputMode="numeric"
            min={0}
            max={200}
            value={gap}
            onChange={(e) => setGap(e.target.value)}
          />
        </Field>
      </div>
    </Modal>
  )
}

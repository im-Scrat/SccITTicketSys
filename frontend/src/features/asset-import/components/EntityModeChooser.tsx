import { Boxes, Layers, Monitor, type LucideIcon } from 'lucide-react'
import { cn } from '@/lib/cn'
import type { ImportMode } from '../types'

const MODES: Array<{ value: ImportMode; title: string; body: string; icon: LucideIcon }> = [
  {
    value: 'pc_units',
    title: 'PC units',
    body: 'Computers that sit in a room: they appear on the floor plan, in maintenance and in fault reports.',
    icon: Monitor,
  },
  {
    value: 'assets',
    title: 'Other equipment',
    body: 'Printers, projectors, UPS, network devices, spare parts — anything tracked by asset tag and catalog model.',
    icon: Boxes,
  },
  {
    value: 'mixed',
    title: 'Both in one file',
    body: 'Each row’s category decides whether it becomes a PC unit or other equipment.',
    icon: Layers,
  },
]

/**
 * What the uploaded file holds (Phase 2.10 R6) — three radio cards, a native
 * radio group underneath so the arrow keys and screen readers work as they
 * would on any form.
 */
export function EntityModeChooser({
  value,
  onChange,
  disabled,
}: {
  value: ImportMode
  onChange: (mode: ImportMode) => void
  disabled?: boolean
}) {
  return (
    <fieldset className="flex flex-col gap-3" disabled={disabled}>
      <legend className="mb-1 text-sm font-semibold text-ink">What does the file hold?</legend>
      <div className="grid gap-3 md:grid-cols-3">
        {MODES.map((mode) => {
          const checked = value === mode.value
          const Icon = mode.icon
          return (
            <label
              key={mode.value}
              className={cn(
                'relative flex cursor-pointer flex-col gap-2 rounded-lg border-2 p-4 transition-colors duration-150',
                checked
                  ? 'border-primary bg-primary-subtle'
                  : 'border-control-border bg-surface hover:bg-surface-sunken',
              )}
            >
              <span className="flex items-center gap-3">
                <input
                  type="radio"
                  name="entity_mode"
                  value={mode.value}
                  checked={checked}
                  onChange={() => onChange(mode.value)}
                  className="size-5 shrink-0 accent-[var(--primary)]"
                />
                <Icon size={24} aria-hidden="true" className="shrink-0 text-muted" />
                <span className="text-base font-semibold text-ink-strong">{mode.title}</span>
              </span>
              <span className="text-sm text-muted">{mode.body}</span>
            </label>
          )
        })}
      </div>
    </fieldset>
  )
}

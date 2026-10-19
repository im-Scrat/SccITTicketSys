import { X } from 'lucide-react'
import { Button } from './Button'

export interface FilterChip {
  key: string
  /** "Laboratories", "Status: Open, Assigned". */
  label: string
  onRemove: () => void
}

interface FilterChipsProps {
  chips: FilterChip[]
  onReset?: () => void
  /** Names the list, e.g. "Filters applied". */
  label?: string
}

/**
 * The filters in effect, each removable — shown only when something differs
 * from the default, so the default view carries no chrome. Each remove button
 * is named for what it removes ("Remove filter: Laboratories"); the label
 * wraps rather than truncating, so nothing is hidden.
 */
export function FilterChips({ chips, onReset, label = 'Filters applied' }: FilterChipsProps) {
  if (chips.length === 0) return null

  return (
    <div className="flex flex-wrap items-center gap-2">
      <ul className="flex flex-wrap items-center gap-2" aria-label={label}>
        {chips.map((chip) => (
          <li
            key={chip.key}
            className="flex min-w-0 items-center gap-1 rounded-md border border-border bg-surface-sunken py-1 pr-1 pl-3 text-xs text-ink"
          >
            <span className="min-w-0 break-words">{chip.label}</span>
            <button
              type="button"
              onClick={chip.onRemove}
              aria-label={`Remove filter: ${chip.label}`}
              className="flex size-8 shrink-0 items-center justify-center rounded-sm text-muted hover:bg-surface hover:text-ink"
            >
              <X size={16} aria-hidden="true" />
            </button>
          </li>
        ))}
      </ul>
      {onReset && (
        <Button variant="ghost" size="sm" onClick={onReset}>
          Reset all
        </Button>
      )}
    </div>
  )
}

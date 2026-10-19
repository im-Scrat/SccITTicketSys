import { useId, useState } from 'react'
import { Input, Select, Spinner } from '@/components/ui'
import { usePcUnitLookup } from '@/hooks/useEquipmentLookup'

interface PcUnitSelectProps {
  /** Selected PC unit uuid, or '' for none. */
  value: string
  onChange: (pcUnitId: string) => void
  label?: string
  /** Reporting a fault that is not about a specific machine is normal. */
  allowEmpty?: boolean
  emptyLabel?: string
  disabled?: boolean
  error?: string
  hint?: string
}

/**
 * The equipment field a form offers — how a ticket says "which machine?".
 *
 * **This is not the Assets module.** It is the sibling of {@link LocationSelect}:
 * a shared component backed by the narrow `/api/lookups/pc-units` endpoint, so a
 * Teacher or Technician can name a machine inside the one form that needs it
 * without any access to the register — no directory, no status, no history, no
 * edit affordances. It lives in `components/` rather than `features/assets/`
 * precisely so consuming features cannot reach the admin slice through it.
 *
 * Naming the PC is what connects a report to the equipment record, and it is the
 * seam the whole Ticket ↔ Asset integration hangs on: everything downstream — the
 * room shown on the ticket, the machine's repair history, the duplicate boost for
 * "this same PC" — follows from this one field being filled in. So it is
 * offered plainly and never required: a reporter who cannot find the unit code
 * should still be able to report the fault.
 */
export function PcUnitSelect({
  value,
  onChange,
  label = 'Affected PC unit',
  allowEmpty = true,
  emptyLabel = 'Not about a specific PC',
  disabled,
  error,
  hint,
}: PcUnitSelectProps) {
  const [search, setSearch] = useState('')
  const id = useId()
  const errorId = `${id}-error`
  const hintId = `${id}-hint`

  const options = usePcUnitLookup(search || undefined)
  const rows = options.data ?? []

  // Keep the current selection reachable even when a search narrows it out.
  const selectedMissing = value !== '' && !rows.some((row) => row.id === value)

  return (
    <div className="flex flex-col gap-2">
      <label htmlFor={id} className="text-sm font-semibold text-ink">
        {label}
      </label>

      <Input
        type="search"
        value={search}
        onChange={(event) => setSearch(event.target.value)}
        placeholder="Filter by unit code, name or asset tag…"
        aria-label={`Filter ${label.toLowerCase()} options`}
        disabled={disabled}
      />

      <div className="relative">
        <Select
          id={id}
          value={value}
          disabled={disabled}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? errorId : hint ? hintId : undefined}
          onChange={(event) => onChange(event.target.value)}
        >
          {(allowEmpty || value === '') && <option value="">{emptyLabel}</option>}
          {selectedMissing && <option value={value}>Current selection</option>}
          {rows.map((option) => (
            <option key={option.id} value={option.id}>
              {option.location ? `${option.label} — ${option.location}` : option.label}
            </option>
          ))}
        </Select>

        {options.isFetching && (
          <span className="pointer-events-none absolute right-10 top-1/2 -translate-y-1/2">
            <Spinner className="size-4" />
          </span>
        )}
      </div>

      {error ? (
        <p id={errorId} role="alert" className="text-sm font-medium text-danger-strong">
          {error}
        </p>
      ) : rows.length === 0 && !options.isFetching ? (
        <p className="text-sm text-muted">
          {search
            ? 'No PC units match that filter.'
            : 'No PC units are available yet — ask an administrator to add them.'}
        </p>
      ) : hint ? (
        <p id={hintId} className="text-sm text-muted">
          {hint}
        </p>
      ) : null}
    </div>
  )
}

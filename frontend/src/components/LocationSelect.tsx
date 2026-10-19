import { useId, useState } from 'react'
import { Input, Select, Spinner } from '@/components/ui'
import { useRoomLookup } from '@/hooks/useLocationLookup'

interface LocationSelectProps {
  /** Selected room uuid, or '' for none. */
  value: string
  onChange: (roomId: string) => void
  label?: string
  /** Narrow the offered rooms (e.g. laboratories only). */
  roomType?: string
  /** Include a "no specific room" choice for non-technical reporters. */
  allowEmpty?: boolean
  emptyLabel?: string
  disabled?: boolean
  error?: string
}

/**
 * The location field a form offers (FR-LOC-005/011) — how Tickets, Assets and
 * Maintenance ask "where is it?".
 *
 * **This is not the Locations module.** It is a shared component backed by the
 * narrow `/api/lookups/rooms` endpoint, so a Teacher or Technician can name a
 * place inside the one form that needs it without any access to site
 * administration: no directory, no tree, no counts, no edit affordances. It lives
 * in `components/` rather than `features/locations/` precisely so consuming
 * features cannot reach the admin slice through it.
 *
 * Deliberately plain: a filter box plus a native `<select>`. A native listbox is
 * what a teacher on an old machine can already operate, works with every screen
 * reader and mobile keyboard, and needs no key map to learn — the picker must be
 * the least anxious control on the screen, not the cleverest.
 *
 * Only *selectable* locations are offered — the server excludes inactive and
 * archived buildings, floors and rooms — so a reporter can never file against a
 * location that is out of service. Each option carries its full
 * "Building · Floor · Room" path, because a room name alone is ambiguous.
 */
export function LocationSelect({
  value,
  onChange,
  label = 'Location',
  roomType,
  allowEmpty = false,
  emptyLabel = 'No specific room',
  disabled,
  error,
}: LocationSelectProps) {
  const [search, setSearch] = useState('')
  const id = useId()
  const errorId = `${id}-error`

  const options = useRoomLookup({
    search: search || undefined,
    room_type: roomType,
  })

  const rows = options.data ?? []
  // Keep the current selection reachable even when a search narrows it out.
  const selectedMissing = value !== '' && !rows.some((row) => row.id === value)

  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="text-xs font-medium text-ink">
        {label}
      </label>

      <Input
        type="search"
        value={search}
        onChange={(event) => setSearch(event.target.value)}
        placeholder="Filter by room, code or building…"
        aria-label={`Filter ${label.toLowerCase()} options`}
        disabled={disabled}
      />

      <div className="relative">
        <Select
          id={id}
          value={value}
          disabled={disabled}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? errorId : undefined}
          onChange={(event) => onChange(event.target.value)}
        >
          {(allowEmpty || value === '') && <option value="">{emptyLabel}</option>}
          {selectedMissing && <option value={value}>Current selection</option>}
          {rows.map((option) => (
            <option key={option.id} value={option.id}>
              {option.label}
            </option>
          ))}
        </Select>

        {options.isFetching && (
          <span className="pointer-events-none absolute right-8 top-1/2 -translate-y-1/2">
            <Spinner className="size-3.5" />
          </span>
        )}
      </div>

      {error ? (
        <p id={errorId} role="alert" className="text-xs text-danger-strong">
          {error}
        </p>
      ) : (
        rows.length === 0 &&
        !options.isFetching && (
          <p className="text-xs text-muted">
            {search
              ? 'No rooms match that filter.'
              : 'No locations are available yet — ask an administrator to add them.'}
          </p>
        )
      )}
    </div>
  )
}

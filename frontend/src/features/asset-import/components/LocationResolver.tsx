import { isAxiosError } from 'axios'
import { MapPin } from 'lucide-react'
import { useState } from 'react'
import { Alert, Badge, Button, Checkbox, EmptyState, Skeleton } from '@/components/ui'
import { useDecideLocation } from '../hooks/mutations'
import { useImportLocations } from '../hooks/queries'
import type { ImportLocation, RoomOption } from '../types'
import { RoomCombobox } from './RoomCombobox'

const MATCH_TONE = {
  matched: 'success',
  ambiguous: 'warning',
  unmatched: 'warning',
  not_selectable: 'warning',
} as const

/**
 * Locations tab (Phase 2.10 R6, SDD DD-97): one decision per distinct
 * location text, applied to every row carrying it. Candidates are offered
 * with their reasons and never pre-applied; any room in the estate can be
 * chosen; "leave without a room" is always an explicit choice.
 */
export function LocationResolver({ importId }: { importId: string }) {
  const locations = useImportLocations(importId)

  if (locations.isLoading) return <Skeleton className="h-40 w-full" />
  if (!locations.data || locations.data.length === 0) {
    return (
      <EmptyState
        icon={<MapPin size={32} aria-hidden="true" />}
        title="No locations in this file"
        description="Rows will be saved without a room."
      />
    )
  }

  const pending = locations.data.filter((l) => l.resolution === 'pending').length

  return (
    <div className="flex flex-col gap-4">
      <p className="text-sm text-muted">
        {pending === 0
          ? 'Every location has a decision.'
          : pending === 1
            ? '1 location needs a decision.'
            : `${pending} locations need a decision.`}{' '}
        A room is applied by itself only when its code, or its building, floor and room, match
        exactly.
      </p>
      <ul className="flex flex-col gap-4">
        {locations.data.map((location) => (
          <li key={location.id}>
            <LocationCard importId={importId} location={location} />
          </li>
        ))}
      </ul>
    </div>
  )
}

function LocationCard({ importId, location }: { importId: string; location: ImportLocation }) {
  const decide = useDecideLocation(importId)
  const [room, setRoom] = useState<{ id: string; label: string; selectable: boolean } | null>(
    location.room
      ? { id: location.room.id, label: location.room.label, selectable: location.room.selectable }
      : null,
  )
  const [assignAnyway, setAssignAnyway] = useState(location.assign_anyway)
  const error = isAxiosError(decide.error)
    ? (Object.values(
        (decide.error.response?.data as { errors?: Record<string, string[]> })?.errors ?? {},
      )[0]?.[0] ?? 'The decision was not saved.')
    : null

  const choose = (
    option: RoomOption | { id: string; label: string; selectable: boolean } | null,
  ) => {
    setRoom(option ? { id: option.id, label: option.label, selectable: option.selectable } : null)
    setAssignAnyway(false)
  }

  const rows = location.row_count === 1 ? '1 row' : `${location.row_count.toLocaleString()} rows`
  const headingId = `loc-${location.id}`

  return (
    <article
      aria-labelledby={headingId}
      className="flex flex-col gap-4 rounded-md border border-border p-4"
    >
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h3 id={headingId} className="break-words text-base font-semibold text-ink-strong">
            {location.is_blank ? 'Rows with no location' : location.source_text}
          </h3>
          <p className="text-sm text-muted">{rows}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Badge tone={location.resolution === 'pending' ? MATCH_TONE[location.match] : 'success'}>
            {location.resolution === 'pending' ? location.match_label : location.resolution_label}
          </Badge>
        </div>
      </div>

      {location.resolution === 'room' && location.room && (
        <p className="text-sm text-ink">
          Saved in <span className="font-semibold">{location.room.label}</span>
          {location.decided ? '' : ' — matched exactly.'}
        </p>
      )}
      {location.resolution === 'unassigned' && (
        <p className="text-sm text-ink">These rows will be saved without a room.</p>
      )}

      {location.candidates.length > 0 && location.resolution === 'pending' && (
        <fieldset className="flex flex-col gap-2">
          <legend className="mb-1 text-sm font-semibold text-ink">Possible rooms</legend>
          {location.candidates.map((candidate) => (
            <label
              key={candidate.uuid}
              className="flex cursor-pointer items-start gap-3 text-sm text-ink"
            >
              <input
                type="radio"
                name={`candidate-${location.id}`}
                checked={room?.id === candidate.uuid}
                onChange={() =>
                  choose({
                    id: candidate.uuid,
                    label: candidate.label,
                    selectable: candidate.selectable,
                  })
                }
                className="mt-1 size-5 accent-[var(--primary)]"
              />
              <span className="min-w-0">
                <span className="block break-words font-semibold">{candidate.label}</span>
                <span className="block text-xs text-muted">
                  {candidate.reason}
                  {candidate.selectable || candidate.reason.includes('not in use')
                    ? ''
                    : ' · not in use'}
                </span>
              </span>
            </label>
          ))}
        </fieldset>
      )}

      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
        <RoomCombobox
          label={location.candidates.length > 0 ? 'Or any room' : 'Choose a room'}
          value={room?.id ?? null}
          selectedLabel={room?.label}
          onChange={choose}
        />
        <div className="flex flex-wrap gap-3">
          <Button
            size="sm"
            variant="secondary"
            disabled={!room || decide.isPending || (!room.selectable && !assignAnyway)}
            onClick={() =>
              room &&
              decide.mutate({
                location: location.id,
                decision: { resolution: 'room', room: room.id, assign_anyway: assignAnyway },
              })
            }
          >
            Use this room
          </Button>
          <Button
            size="sm"
            variant="ghost"
            disabled={decide.isPending}
            onClick={() =>
              decide.mutate({ location: location.id, decision: { resolution: 'unassigned' } })
            }
          >
            Leave {location.row_count === 1 ? 'it' : `these ${location.row_count}`} without a room
          </Button>
        </div>
      </div>

      {room && !room.selectable && (
        <Checkbox
          label="This room is not in use. Assign it anyway"
          checked={assignAnyway}
          onChange={(e) => setAssignAnyway(e.target.checked)}
        />
      )}
      {error && <Alert tone="error">{error}</Alert>}
    </article>
  )
}

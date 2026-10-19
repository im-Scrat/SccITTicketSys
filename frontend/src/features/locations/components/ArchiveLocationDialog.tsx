import axios from 'axios'
import { AlertTriangle } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Alert, Button, Modal, Select } from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import type { LocationLevel } from '../api/locationsApi'
import { useRoomLookup } from '@/hooks/useLocationLookup'
import { useArchiveLocation, useReassignRoomOccupants } from '../hooks/mutations'
import type { Blockers, LocationInUseError } from '../types'

interface ArchiveLocationDialogProps {
  open: boolean
  onClose: () => void
  level: LocationLevel
  id: string
  name: string
  /** Blocker counts already known from the detail endpoint, if any. */
  knownBlockers?: Blockers
  onArchived?: () => void
}

const LEVEL_NOUN: Record<LocationLevel, string> = {
  buildings: 'building',
  floors: 'floor',
  rooms: 'room',
}

/** Read the 422 `location_in_use` body out of a failed archive. */
function readInUse(error: unknown): LocationInUseError | null {
  if (axios.isAxiosError(error) && error.response?.status === 422) {
    const data = error.response.data as Partial<LocationInUseError> | undefined
    if (data?.code === 'location_in_use') return data as LocationInUseError
  }
  return null
}

function describeBlockers(blockers: Blockers): string[] {
  const parts: Array<[keyof Blockers, string]> = [
    ['pc_units', 'PC unit'],
    ['assets', 'asset'],
    ['consumables', 'consumable line'],
    ['open_tickets', 'open ticket'],
  ]

  return parts
    .filter(([key]) => blockers[key] > 0)
    .map(([key, noun]) => `${blockers[key]} ${noun}${blockers[key] === 1 ? '' : 's'}`)
}

/**
 * Archive a location, or explain why it cannot be archived yet.
 *
 * Archiving is never destructive — the record and its history stay — so the
 * dialog says so plainly instead of using alarm language. When the server refuses
 * (FR-LOC-004), the blocker report is rendered as the *next action*: for a single
 * room, offer to move its equipment and stock to another room; for a building or
 * floor, name the rooms that need attention. Open tickets are never silently
 * moved — a ticket records where the problem was, so it must be resolved or
 * closed first, and the copy says that.
 */
export function ArchiveLocationDialog({
  open,
  onClose,
  level,
  id,
  name,
  knownBlockers,
  onArchived,
}: ArchiveLocationDialogProps) {
  const [inUse, setInUse] = useState<LocationInUseError | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [target, setTarget] = useState('')

  const archive = useArchiveLocation()
  const reassign = useReassignRoomOccupants(id)
  const rooms = useRoomLookup({}, open && level === 'rooms')

  useEffect(() => {
    if (!open) return
    setInUse(null)
    setError(null)
    setTarget('')
  }, [open])

  const blockers = inUse?.blockers ?? knownBlockers
  const blocked = Boolean(blockers && Object.values(blockers).some((count) => count > 0))
  const onlyTicketsBlock = Boolean(
    blockers &&
    blockers.open_tickets > 0 &&
    blockers.pc_units === 0 &&
    blockers.assets === 0 &&
    blockers.consumables === 0,
  )

  const runArchive = async () => {
    setError(null)
    try {
      await archive.mutateAsync({ level, id })
      onArchived?.()
      onClose()
    } catch (caught) {
      const report = readInUse(caught)
      if (report) setInUse(report)
      else setError(getErrorMessage(caught))
    }
  }

  const runReassign = async () => {
    setError(null)
    try {
      await reassign.mutateAsync(target)
      setInUse(null)
      setTarget('')
      await runArchive()
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  const noun = LEVEL_NOUN[level]
  const canReassign = level === 'rooms' && blocked && !onlyTicketsBlock
  // When tickets also block, moving the equipment is progress but not the whole
  // job — so the button promises only what it can deliver.
  const ticketsAlsoBlock = Boolean(blockers && blockers.open_tickets > 0)

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={blocked ? `${name} is still in use` : `Archive ${noun}?`}
      description={
        blocked
          ? undefined
          : level === 'rooms'
            ? 'The room and its history are kept, and it stops being offered as a location.'
            : `The ${noun} and everything inside it are archived together. Nothing is deleted — you can restore it.`
      }
      size="sm"
      footer={
        <>
          <Button variant="ghost" size="sm" onClick={onClose}>
            {blocked ? 'Close' : 'Cancel'}
          </Button>
          {canReassign ? (
            <Button
              size="sm"
              disabled={!target}
              loading={reassign.isPending || archive.isPending}
              onClick={() => void runReassign()}
            >
              {ticketsAlsoBlock ? 'Move equipment' : 'Move and archive'}
            </Button>
          ) : (
            !blocked && (
              <Button
                size="sm"
                variant="danger"
                loading={archive.isPending}
                onClick={() => void runArchive()}
              >
                Archive {noun}
              </Button>
            )
          )}
        </>
      }
    >
      <div className="flex flex-col gap-3">
        {error && <Alert tone="error">{error}</Alert>}

        {blocked && blockers && (
          <>
            <div className="flex gap-2.5 rounded-md bg-warning-subtle p-3">
              <AlertTriangle
                size={16}
                className="mt-0.5 shrink-0 text-warning-strong"
                aria-hidden="true"
              />
              <div className="text-sm text-ink">
                <p>
                  It still holds {describeBlockers(blockers).join(', ')}. Archiving now would leave
                  them in a location nobody can see.
                </p>
                {blockers.open_tickets > 0 && (
                  <p className="mt-1.5 text-muted">
                    Open tickets have to be resolved or closed first — a ticket records where the
                    problem was, so it is never moved.
                  </p>
                )}
              </div>
            </div>

            {inUse && inUse.rooms.length > 0 && level !== 'rooms' && (
              <div>
                <p className="text-xs font-medium text-ink">Rooms needing attention</p>
                <ul className="mt-1.5 flex flex-col gap-1">
                  {inUse.rooms.map((room) => (
                    <li key={room.id} className="text-sm text-muted">
                      <span className="text-ink">{room.label}</span>
                      {' — '}
                      {describeBlockers(room.blockers).join(', ')}
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {canReassign && (
              <label className="flex flex-col gap-1.5 text-xs font-medium text-ink">
                Move its equipment and stock to
                <Select value={target} onChange={(event) => setTarget(event.target.value)}>
                  <option value="">Choose a room…</option>
                  {(rooms.data ?? [])
                    .filter((option) => option.id !== id)
                    .map((option) => (
                      <option key={option.id} value={option.id}>
                        {option.label}
                      </option>
                    ))}
                </Select>
              </label>
            )}
          </>
        )}

        {!blocked && level !== 'rooms' && (
          <p className="text-sm text-muted">
            Its floors and rooms are archived with it, and restoring the {noun} brings back exactly
            those — anything you archived separately stays archived.
          </p>
        )}
      </div>
    </Modal>
  )
}

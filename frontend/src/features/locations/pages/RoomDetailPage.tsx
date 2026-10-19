import {
  ArchiveRestore,
  ArrowLeft,
  DoorClosed,
  Package,
  Pencil,
  Power,
  PowerOff,
  Ticket as TicketIcon,
  Trash2,
} from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { AuditTimeline } from '@/components/AuditTimeline'
import { Alert, Badge, Button, PageLoader, StatCard, Surface } from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { formatDateTime } from '@/lib/datetime'
import { ArchiveLocationDialog } from '../components/ArchiveLocationDialog'
import { RoomFormDrawer } from '../components/RoomFormDrawer'
import { RoomTypeBadge } from '../components/RoomTypeBadge'
import { useRestoreLocation, useSetLocationActive } from '../hooks/mutations'
import { useLocationAudit, useRoom } from '../hooks/queries'

/**
 * One room: what it is, what it currently holds, and everything that has
 * happened to it. The occupancy figures are also the reason an archive may be
 * refused, so they are shown plainly rather than hidden behind the attempt.
 */
export default function RoomDetailPage() {
  const { id } = useParams<{ id: string }>()
  const { hasPermission } = useAuth()
  const canUpdate = hasPermission('locations.update')
  const canDelete = hasPermission('locations.delete')

  const { data, isLoading, isError } = useRoom(id)
  const audit = useLocationAudit('rooms', id)
  const setActive = useSetLocationActive()
  const restore = useRestoreLocation()

  const [editOpen, setEditOpen] = useState(false)
  const [archiveOpen, setArchiveOpen] = useState(false)
  const [flash, setFlash] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const room = data?.data
  useDocumentMeta({ title: room ? room.name : 'Room' })

  if (isLoading) return <PageLoader />
  if (isError || !room) {
    return <Alert tone="error">We couldn’t load this room.</Alert>
  }

  const meta = data?.meta

  const toggleActive = async () => {
    setError(null)
    try {
      await setActive.mutateAsync({ level: 'rooms', id: room.id, active: !room.is_active })
      setFlash(
        room.is_active
          ? 'Room deactivated — it is no longer offered as a location.'
          : 'Room activated.',
      )
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <Link
          to="/app/locations"
          className="inline-flex items-center gap-1.5 rounded-sm text-muted hover:text-ink"
        >
          <ArrowLeft size={15} aria-hidden="true" />
          Locations
        </Link>
        {room.building?.id && (
          <>
            <span className="text-faint" aria-hidden="true">
              /
            </span>
            <Link
              to={`/app/locations/buildings/${room.building.id}`}
              className="rounded-sm text-muted hover:text-ink"
            >
              {room.building.name}
            </Link>
          </>
        )}
      </div>

      <header className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <DoorClosed size={20} className="text-muted" aria-hidden="true" />
            <h1 className="text-2xl font-semibold tracking-[-0.02em] text-ink-strong">
              {room.name}
            </h1>
            <span className="font-mono text-sm text-muted">{room.code}</span>
            <RoomTypeBadge type={room.room_type} label={room.room_type_label} />
            {room.archived && <Badge tone="outline">Archived</Badge>}
            {!room.archived && !room.is_active && <Badge tone="warning">Inactive</Badge>}
          </div>
          <p className="mt-1 text-sm text-muted">
            {[
              room.building?.name,
              room.floor ? room.floor.name : null,
              room.room_number ? `Room ${room.room_number}` : null,
              room.capacity !== null ? `${room.capacity} seats` : null,
            ]
              .filter(Boolean)
              .join(' · ')}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {canUpdate && !room.archived && (
            <>
              <Button
                variant="secondary"
                size="sm"
                leftIcon={<Pencil size={15} />}
                onClick={() => setEditOpen(true)}
              >
                Edit
              </Button>
              <Button
                variant="secondary"
                size="sm"
                leftIcon={room.is_active ? <PowerOff size={15} /> : <Power size={15} />}
                loading={setActive.isPending}
                onClick={() => void toggleActive()}
              >
                {room.is_active ? 'Deactivate' : 'Activate'}
              </Button>
            </>
          )}
          {canDelete && !room.archived && (
            <Button
              variant="ghost"
              size="sm"
              leftIcon={<Trash2 size={15} />}
              onClick={() => setArchiveOpen(true)}
            >
              Archive
            </Button>
          )}
          {canDelete && room.archived && (
            <Button
              variant="secondary"
              size="sm"
              leftIcon={<ArchiveRestore size={15} />}
              loading={restore.isPending}
              onClick={async () => {
                setError(null)
                try {
                  await restore.mutateAsync({ level: 'rooms', id: room.id })
                  setFlash('Room restored.')
                } catch (caught) {
                  setError(getErrorMessage(caught))
                }
              }}
            >
              Restore
            </Button>
          )}
        </div>
      </header>

      {flash && <Alert tone="success">{flash}</Alert>}
      {error && <Alert tone="error">{error}</Alert>}

      {!room.selectable && !room.archived && (
        <Alert tone="info">
          This room is not currently offered as a location — either it is inactive, or its building
          is.
        </Alert>
      )}

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatCard
          label="PC units"
          value={room.pc_units_count}
          icon={<DoorClosed size={15} />}
          hint="Placed in this room"
        />
        <StatCard label="Assets" value={room.assets_count} icon={<Package size={15} />} />
        <StatCard label="Stock lines" value={room.consumables_count} icon={<Package size={15} />} />
        <StatCard
          label="Tickets"
          value={room.tickets_count}
          icon={<TicketIcon size={15} />}
          hint="All time"
        />
      </div>

      {meta?.in_use && !room.archived && (
        <Alert tone="info">
          {meta.blockers.open_tickets > 0 &&
          meta.blockers.pc_units === 0 &&
          meta.blockers.assets === 0 &&
          meta.blockers.consumables === 0
            ? 'This room has open tickets. Resolve or close them before archiving it.'
            : 'This room still holds equipment or stock. Archiving offers to move them to another room first.'}
        </Alert>
      )}

      <Surface className="p-4">
        <h2 className="text-sm font-semibold text-ink-strong">Details</h2>
        <dl className="mt-3 grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
          <dt className="text-muted">Description</dt>
          <dd className="text-ink">{room.description ?? '—'}</dd>
          <dt className="text-muted">Created</dt>
          <dd className="text-ink tnum">
            {formatDateTime(room.created_at)}
            {room.created_by ? ` · by ${room.created_by}` : ''}
          </dd>
          <dt className="text-muted">Last updated</dt>
          <dd className="text-ink tnum">
            {formatDateTime(room.updated_at)}
            {room.updated_by ? ` · by ${room.updated_by}` : ''}
          </dd>
          {room.archived && (
            <>
              <dt className="text-muted">Archived</dt>
              <dd className="text-ink tnum">{formatDateTime(room.archived_at)}</dd>
            </>
          )}
        </dl>
      </Surface>

      <Surface className="p-4">
        <h2 className="mb-3 text-sm font-semibold text-ink-strong">History</h2>
        <AuditTimeline
          entries={audit.data?.data}
          isLoading={audit.isLoading}
          emptyDescription="Edits, moves, availability changes and reassignments for this room will appear here."
        />
      </Surface>

      {canUpdate && (
        <RoomFormDrawer open={editOpen} onClose={() => setEditOpen(false)} room={room} />
      )}
      {canDelete && (
        <ArchiveLocationDialog
          open={archiveOpen}
          onClose={() => setArchiveOpen(false)}
          level="rooms"
          id={room.id}
          name={room.name}
          knownBlockers={meta?.blockers}
          onArchived={() => setFlash('Room archived.')}
        />
      )}
    </div>
  )
}

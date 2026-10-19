import {
  ArchiveRestore,
  ArrowLeft,
  Building2,
  Layers,
  Pencil,
  Plus,
  PowerOff,
  Power,
  Trash2,
} from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { AuditTimeline } from '@/components/AuditTimeline'
import {
  Alert,
  Badge,
  Button,
  EmptyState,
  PageLoader,
  Surface,
  Table,
  TBody,
  Td,
  Th,
  THead,
  Tr,
} from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { formatDateTime } from '@/lib/datetime'
import { ArchiveLocationDialog } from '../components/ArchiveLocationDialog'
import { BuildingFormDrawer } from '../components/BuildingFormDrawer'
import { FloorFormDrawer } from '../components/FloorFormDrawer'
import { RoomFormDrawer } from '../components/RoomFormDrawer'
import { useRestoreLocation, useSetLocationActive } from '../hooks/mutations'
import { useBuilding, useLocationAudit } from '../hooks/queries'
import type { FloorItem } from '../types'

/**
 * One building: its details, its floors, and its history. Floor-level actions
 * live here rather than on a separate page — a floor is only meaningful inside
 * its building, and this keeps the whole structure editable in one place.
 */
export default function BuildingDetailPage() {
  const { id } = useParams<{ id: string }>()
  const navigate = useNavigate()
  const { hasPermission } = useAuth()
  const canCreate = hasPermission('locations.create')
  const canUpdate = hasPermission('locations.update')
  const canDelete = hasPermission('locations.delete')

  const { data, isLoading, isError } = useBuilding(id)
  const audit = useLocationAudit('buildings', id)
  const setActive = useSetLocationActive()
  const restore = useRestoreLocation()
  const restoreFloor = useRestoreLocation()

  const [editOpen, setEditOpen] = useState(false)
  const [newFloorOpen, setNewFloorOpen] = useState(false)
  const [editFloor, setEditFloor] = useState<FloorItem | null>(null)
  const [newRoomFloor, setNewRoomFloor] = useState<FloorItem | null>(null)
  const [archiveOpen, setArchiveOpen] = useState(false)
  const [archiveFloorTarget, setArchiveFloorTarget] = useState<FloorItem | null>(null)
  const [flash, setFlash] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const building = data?.data
  useDocumentMeta({ title: building ? building.name : 'Building' })

  if (isLoading) return <PageLoader />
  if (isError || !building) {
    return <Alert tone="error">We couldn’t load this building.</Alert>
  }

  const meta = data?.meta
  const floors = building.floors ?? []

  const toggleActive = async () => {
    setError(null)
    try {
      await setActive.mutateAsync({
        level: 'buildings',
        id: building.id,
        active: !building.is_active,
      })
      setFlash(
        building.is_active
          ? 'Building deactivated — its floors and rooms are no longer offered as locations.'
          : 'Building activated.',
      )
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  return (
    <div className="flex flex-col gap-6">
      <div>
        <Link
          to="/app/locations"
          className="inline-flex items-center gap-1.5 rounded-sm text-sm text-muted hover:text-ink"
        >
          <ArrowLeft size={15} aria-hidden="true" />
          Locations
        </Link>
      </div>

      <header className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <Building2 size={20} className="text-muted" aria-hidden="true" />
            <h1 className="text-2xl font-semibold tracking-[-0.02em] text-ink-strong">
              {building.name}
            </h1>
            <span className="font-mono text-sm text-muted">{building.code}</span>
            {building.archived && <Badge tone="outline">Archived</Badge>}
            {!building.archived && !building.is_active && <Badge tone="warning">Inactive</Badge>}
          </div>
          <p className="mt-1 text-sm text-muted">
            {building.floors_count} floor{building.floors_count === 1 ? '' : 's'} ·{' '}
            {building.rooms_count} room{building.rooms_count === 1 ? '' : 's'}
            {building.address ? ` · ${building.address}` : ''}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {canUpdate && !building.archived && (
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
                leftIcon={building.is_active ? <PowerOff size={15} /> : <Power size={15} />}
                loading={setActive.isPending}
                onClick={() => void toggleActive()}
              >
                {building.is_active ? 'Deactivate' : 'Activate'}
              </Button>
            </>
          )}
          {canCreate && !building.archived && (
            <Button size="sm" leftIcon={<Plus size={15} />} onClick={() => setNewFloorOpen(true)}>
              Add floor
            </Button>
          )}
          {canDelete && !building.archived && (
            <Button
              variant="ghost"
              size="sm"
              leftIcon={<Trash2 size={15} />}
              onClick={() => setArchiveOpen(true)}
            >
              Archive
            </Button>
          )}
          {canDelete && building.archived && (
            <Button
              variant="secondary"
              size="sm"
              leftIcon={<ArchiveRestore size={15} />}
              loading={restore.isPending}
              onClick={async () => {
                setError(null)
                try {
                  await restore.mutateAsync({ level: 'buildings', id: building.id })
                  setFlash('Building restored with the floors and rooms archived alongside it.')
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
      {meta?.in_use && !building.archived && (
        <Alert tone="info">
          This building holds live equipment or open tickets, so it cannot be archived yet.
        </Alert>
      )}

      <Surface className="overflow-hidden">
        <div className="flex items-center justify-between gap-3 border-b border-border px-3 py-2.5">
          <h2 className="text-sm font-semibold text-ink-strong">Floors</h2>
        </div>

        {floors.length === 0 ? (
          <EmptyState
            className="border-0"
            icon={<Layers size={22} />}
            title="No floors yet"
            description="Add a floor, then the rooms that sit on it. Basements use a negative number."
          />
        ) : (
          <Table>
            <THead>
              <Tr>
                <Th className="w-20 text-right">Floor</Th>
                <Th>Name</Th>
                <Th className="text-right">Rooms</Th>
                <Th className="w-px" aria-label="Actions" />
              </Tr>
            </THead>
            <TBody>
              {floors.map((floor) => (
                <Tr key={floor.id}>
                  <Td className="text-right text-muted tnum">{floor.floor_number}</Td>
                  <Td>
                    <span className="font-medium text-ink-strong">{floor.name}</span>
                    {floor.archived && (
                      <Badge tone="outline" className="ml-2">
                        Archived
                      </Badge>
                    )}
                    {floor.description && (
                      <p className="mt-0.5 text-xs text-muted">{floor.description}</p>
                    )}
                  </Td>
                  <Td className="text-right text-ink tnum">{floor.rooms_count}</Td>
                  <Td>
                    <div className="flex items-center justify-end gap-1">
                      {canCreate && !floor.archived && (
                        <Button variant="ghost" size="sm" onClick={() => setNewRoomFloor(floor)}>
                          Add room
                        </Button>
                      )}
                      {canUpdate && !floor.archived && (
                        <Button variant="ghost" size="sm" onClick={() => setEditFloor(floor)}>
                          Edit
                        </Button>
                      )}
                      {canDelete && !floor.archived && (
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => setArchiveFloorTarget(floor)}
                        >
                          Archive
                        </Button>
                      )}
                      {canDelete && floor.archived && (
                        <Button
                          variant="ghost"
                          size="sm"
                          loading={restoreFloor.isPending}
                          onClick={async () => {
                            setError(null)
                            try {
                              await restoreFloor.mutateAsync({ level: 'floors', id: floor.id })
                              setFlash(`${floor.name} restored.`)
                            } catch (caught) {
                              setError(getErrorMessage(caught))
                            }
                          }}
                        >
                          Restore
                        </Button>
                      )}
                    </div>
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        )}
      </Surface>

      <Surface className="p-4">
        <h2 className="text-sm font-semibold text-ink-strong">Details</h2>
        <dl className="mt-3 grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
          <dt className="text-muted">Description</dt>
          <dd className="text-ink">{building.description ?? '—'}</dd>
          <dt className="text-muted">Created</dt>
          <dd className="text-ink tnum">
            {formatDateTime(building.created_at)}
            {building.created_by ? ` · by ${building.created_by}` : ''}
          </dd>
          <dt className="text-muted">Last updated</dt>
          <dd className="text-ink tnum">
            {formatDateTime(building.updated_at)}
            {building.updated_by ? ` · by ${building.updated_by}` : ''}
          </dd>
          {building.archived && (
            <>
              <dt className="text-muted">Archived</dt>
              <dd className="text-ink tnum">{formatDateTime(building.archived_at)}</dd>
            </>
          )}
        </dl>
      </Surface>

      <Surface className="p-4">
        <h2 className="mb-3 text-sm font-semibold text-ink-strong">History</h2>
        <AuditTimeline
          entries={audit.data?.data}
          isLoading={audit.isLoading}
          emptyDescription="Changes to this building — edits, availability, archiving — will appear here."
        />
      </Surface>

      {canUpdate && (
        <BuildingFormDrawer
          open={editOpen}
          onClose={() => setEditOpen(false)}
          building={building}
        />
      )}
      {canCreate && (
        <FloorFormDrawer
          open={newFloorOpen}
          onClose={() => setNewFloorOpen(false)}
          buildingId={building.id}
        />
      )}
      {canUpdate && editFloor && (
        <FloorFormDrawer
          open
          onClose={() => setEditFloor(null)}
          buildingId={building.id}
          floor={editFloor}
        />
      )}
      {canCreate && newRoomFloor && (
        <RoomFormDrawer
          open
          onClose={() => setNewRoomFloor(null)}
          defaultBuildingId={building.id}
          defaultFloorId={newRoomFloor.id}
          onSaved={(room) => navigate(`/app/locations/rooms/${room.id}`)}
        />
      )}
      {canDelete && (
        <ArchiveLocationDialog
          open={archiveOpen}
          onClose={() => setArchiveOpen(false)}
          level="buildings"
          id={building.id}
          name={building.name}
          knownBlockers={meta?.blockers}
          onArchived={() => setFlash('Building archived with its floors and rooms.')}
        />
      )}
      {canDelete && archiveFloorTarget && (
        <ArchiveLocationDialog
          open
          onClose={() => setArchiveFloorTarget(null)}
          level="floors"
          id={archiveFloorTarget.id}
          name={archiveFloorTarget.name}
          onArchived={() => setFlash(`${archiveFloorTarget.name} archived with its rooms.`)}
        />
      )}
    </div>
  )
}

import { useMemo, useState } from 'react'
import { ArrowRightToLine, GripVertical, MapPin, Plus } from 'lucide-react'
import { isAxiosError } from 'axios'
import {
  Alert,
  Button,
  ConfirmDialog,
  PcStatusBadge,
  SearchInput,
  Select,
  Skeleton,
  Tabs,
} from '@/components/ui'
import { usePcUnitsList } from '@/features/assets/hooks/queries'
import type { PcUnitListItem } from '@/features/assets/types'
import { useDebouncedValue } from '@/hooks/useDebouncedValue'
import { cn } from '@/lib/cn'
import { PC_STATUS, PC_STATUS_VALUES } from '@/lib/pcStatus'
import { startDrag } from '../lib/dragItem'
import type { LayoutDoc, ObjectKey } from '../lib/layoutDocument'
import { useMovePcToRoom } from '../hooks/mutations'
import type { RoomPlan } from '../types'

type Placement = 'all' | 'unplaced' | 'placed'

interface WorkstationsPanelProps {
  plan: RoomPlan
  doc: LayoutDoc
  editing: boolean
  /** The viewer may move PCs between rooms (assets.view + assets.update). */
  canMoveIn: boolean
  selection: ObjectKey[]
  onPlace: (pcId: string) => void
  onSelect: (pcId: string) => void
  onAnnounce: (message: string) => void
}

/**
 * The room's machines, placed and not, and — for an administrator who may move
 * assets — the machines registered elsewhere (Phase 2.11).
 *
 * This list is also the canvas's text alternative: every workstation, its
 * status in words and whether it is placed, reachable without a pointer.
 * Placing is "Place" (next free spot, then move it) or, with a mouse, dragging
 * a row onto the canvas.
 */
export function WorkstationsPanel(props: WorkstationsPanelProps) {
  const [tab, setTab] = useState<'room' | 'other'>('room')

  return (
    <section aria-label="Workstations" className="flex min-h-0 flex-col gap-4">
      <Tabs
        tabs={[
          {
            value: 'room',
            label: 'In this room',
            count: props.plan.pcs.length + props.plan.unplaced.length,
          },
          ...(props.canMoveIn ? [{ value: 'other', label: 'Other rooms' }] : []),
        ]}
        value={tab}
        onChange={(value) => setTab(value as 'room' | 'other')}
      />
      {tab === 'room' || !props.canMoveIn ? <InThisRoom {...props} /> : <OtherRooms {...props} />}
    </section>
  )
}

function InThisRoom({ plan, doc, editing, selection, onPlace, onSelect }: WorkstationsPanelProps) {
  const [search, setSearch] = useState('')
  const [placement, setPlacement] = useState<Placement>('all')
  const [status, setStatus] = useState('')

  const rows = useMemo(() => {
    const all = [...plan.pcs, ...plan.unplaced]
      .filter((pc, i, list) => list.findIndex((p) => p.id === pc.id) === i)
      .sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true }))
    const term = search.trim().toLowerCase()
    return all.filter((pc) => {
      const placed = pc.id in doc.workstations
      if (placement === 'placed' && !placed) return false
      if (placement === 'unplaced' && placed) return false
      if (status && pc.status.value !== status) return false
      return (
        !term || pc.name.toLowerCase().includes(term) || pc.unit_code.toLowerCase().includes(term)
      )
    })
  }, [plan.pcs, plan.unplaced, doc.workstations, search, placement, status])

  const unplaced = [...plan.pcs, ...plan.unplaced].filter(
    (pc) => !(pc.id in doc.workstations),
  ).length

  return (
    <div className="flex min-h-0 flex-col gap-3">
      <SearchInput value={search} onChange={setSearch} placeholder="Search name or code" />
      <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Show">
        {(['all', 'unplaced', 'placed'] as Placement[]).map((value) => (
          <button
            key={value}
            type="button"
            aria-pressed={placement === value}
            onClick={() => setPlacement(value)}
            className={cn(
              'rounded-full border px-3 py-1 text-sm font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus',
              placement === value
                ? 'border-primary bg-primary-subtle text-primary-strong'
                : 'border-border text-muted hover:bg-surface-sunken',
            )}
          >
            {value === 'all' ? 'All' : value === 'unplaced' ? `Not placed (${unplaced})` : 'Placed'}
          </button>
        ))}
      </div>
      <Select
        aria-label="Filter by status"
        value={status}
        onChange={(e) => setStatus(e.target.value)}
      >
        <option value="">Every status</option>
        {PC_STATUS_VALUES.map((value) => (
          <option key={value} value={value}>
            {PC_STATUS[value].label}
          </option>
        ))}
      </Select>

      {rows.length === 0 ? (
        <p className="py-6 text-center text-sm text-muted">
          {plan.pcs.length + plan.unplaced.length === 0
            ? 'No PCs are registered to this room yet.'
            : 'No PCs match these filters.'}
        </p>
      ) : (
        <ul
          className="-mx-1 flex min-h-0 flex-col overflow-y-auto px-1"
          aria-label="PCs in this room"
        >
          {rows.map((pc) => {
            const placed = pc.id in doc.workstations
            const isSelected = selection.includes(`pc:${pc.id}`)
            const draggable = editing && !placed
            return (
              <li
                key={pc.id}
                draggable={draggable}
                onDragStart={draggable ? (e) => startDrag(e, { type: 'pc', id: pc.id }) : undefined}
                className={cn(
                  'group flex items-center gap-3 border-b border-border py-3 last:border-b-0',
                  isSelected && 'bg-primary-subtle/50',
                )}
              >
                {draggable && (
                  <GripVertical
                    className="size-4 shrink-0 cursor-grab text-faint"
                    aria-hidden="true"
                  />
                )}
                <div className="min-w-0 flex-1">
                  {placed ? (
                    <button
                      type="button"
                      onClick={() => onSelect(pc.id)}
                      className="text-left text-sm font-semibold text-ink-strong underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                    >
                      {pc.name}
                    </button>
                  ) : (
                    <span className="text-sm font-semibold text-ink-strong">{pc.name}</span>
                  )}
                  <span className="block font-mono text-xs text-muted">{pc.unit_code}</span>
                  <div className="mt-1 flex flex-wrap items-center gap-2">
                    <PcStatusBadge status={pc.status.value} />
                    <span className="text-xs text-muted">{placed ? 'Placed' : 'Not placed'}</span>
                  </div>
                </div>
                {editing && !placed && (
                  <Button
                    size="sm"
                    variant="secondary"
                    aria-label={`Place ${pc.name}`}
                    leftIcon={<Plus className="size-4" aria-hidden="true" />}
                    onClick={() => onPlace(pc.id)}
                  >
                    Place
                  </Button>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}

function OtherRooms({ plan, onAnnounce }: WorkstationsPanelProps) {
  const [search, setSearch] = useState('')
  const [onlyUnassigned, setOnlyUnassigned] = useState(false)
  const [moving, setMoving] = useState<PcUnitListItem | null>(null)
  const debounced = useDebouncedValue(search, 250)
  const move = useMovePcToRoom(plan.room.id)

  const list = usePcUnitsList({
    search: debounced || undefined,
    exclude_room: plan.room.id,
    unassigned: onlyUnassigned ? 1 : undefined,
    per_page: 20,
    sort: 'pc_name',
    direction: 'asc',
  })

  const confirm = () => {
    if (!moving) return
    move.mutate(moving.id, {
      onSuccess: () => {
        onAnnounce(
          `${moving.pc_name} moved to ${plan.room.name}. It is now in the list of PCs to place.`,
        )
        setMoving(null)
      },
    })
  }

  const errorMessage =
    move.error && isAxiosError(move.error) && move.error.response?.status === 403
      ? 'You do not have permission to move PCs between rooms.'
      : move.error
        ? 'The PC could not be moved. Try again.'
        : null

  return (
    <div className="flex min-h-0 flex-col gap-3">
      <p className="text-sm text-muted">
        PCs registered to other rooms, or to no room. Moving one here changes its room in the asset
        register; it is never placed silently.
      </p>
      <SearchInput value={search} onChange={setSearch} placeholder="Search all PCs" />
      <label className="flex items-center gap-2 text-sm text-ink">
        <input
          type="checkbox"
          className="size-5 accent-primary"
          checked={onlyUnassigned}
          onChange={(e) => setOnlyUnassigned(e.target.checked)}
        />
        Only PCs with no room
      </label>

      {list.isLoading ? (
        <div className="flex flex-col gap-2">
          <Skeleton className="h-16" />
          <Skeleton className="h-16" />
        </div>
      ) : list.isError ? (
        <Alert tone="error">The PC list could not be loaded.</Alert>
      ) : (list.data?.data.length ?? 0) === 0 ? (
        <p className="py-6 text-center text-sm text-muted">No other PCs match.</p>
      ) : (
        <ul
          className="-mx-1 flex min-h-0 flex-col overflow-y-auto px-1"
          aria-label="PCs in other rooms"
        >
          {list.data!.data.map((pc) => (
            <li
              key={pc.id}
              className="flex items-center gap-3 border-b border-border py-3 last:border-b-0"
            >
              <div className="min-w-0 flex-1">
                <span className="text-sm font-semibold text-ink-strong">{pc.pc_name}</span>
                <span className="block font-mono text-xs text-muted">{pc.unit_code}</span>
                <div className="mt-1 flex flex-wrap items-center gap-2">
                  <PcStatusBadge status={pc.status} />
                  {pc.room ? (
                    <span className="inline-flex items-center gap-1 text-xs text-muted">
                      <MapPin className="size-3.5" aria-hidden="true" />
                      {pc.room.name}
                    </span>
                  ) : (
                    <PcStatusBadge status="unassigned" />
                  )}
                </div>
              </div>
              <Button
                size="sm"
                variant="secondary"
                aria-label={`Move ${pc.pc_name} here`}
                leftIcon={<ArrowRightToLine className="size-4" aria-hidden="true" />}
                onClick={() => setMoving(pc)}
              >
                Move here
              </Button>
            </li>
          ))}
        </ul>
      )}

      <ConfirmDialog
        open={moving !== null}
        onClose={() => {
          setMoving(null)
          move.reset()
        }}
        onConfirm={confirm}
        loading={move.isPending}
        title={`Move ${moving?.pc_name ?? 'this PC'} to ${plan.room.name}?`}
        confirmLabel="Move PC"
        description={
          moving?.room
            ? `It is registered to ${moving.room.name}. Moving it changes its room in the asset register and removes it from ${moving.room.name}'s Floorplan. The change is recorded in its history.`
            : 'It has no room yet. Moving it here assigns it to this room in the asset register. The change is recorded in its history.'
        }
      >
        {errorMessage && <Alert tone="error">{errorMessage}</Alert>}
      </ConfirmDialog>
    </div>
  )
}

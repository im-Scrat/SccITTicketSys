import { isAxiosError } from 'axios'
import { ArrowLeft, LayoutGrid, LayoutTemplate, Rows3 } from 'lucide-react'
import { useId, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Alert, EmptyState, PageLoader, Surface } from '@/components/ui'
import { Button } from '@/components/ui/Button'
import { ConfirmDialog } from '@/components/ui/ConfirmDialog'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import ForbiddenPage from '@/pages/ForbiddenPage'
import { CreateLayoutForm } from '../components/CreateLayoutForm'
import { FloorPlanCanvas } from '../components/FloorPlanCanvas'
import { FloorPlanLegend } from '../components/FloorPlanLegend'
import { FloorPlanToolbar } from '../components/FloorPlanToolbar'
import { PcInspectorPanel } from '../components/PcInspectorPanel'
import { PcUnitList } from '../components/PcUnitList'
import { PlacementPanel } from '../components/PlacementPanel'
import { UnplacedUnits } from '../components/UnplacedUnits'
import { useCreateLayout, usePlacePcUnit } from '../hooks/mutations'
import { useFloorPlanChannel } from '../hooks/useFloorPlanChannel'
import { useRoomPlan } from '../hooks/queries'
import { placementErrorMessage } from '../lib/errors'
import { describePoint, freeSpot, nodeMetrics, previewPoint, type Point } from '../lib/placement'
import { ALERT_STATUSES } from '../lib/presentation'
import type { LayoutCreateRequest, PlacedPc, RoomPlan } from '../types'

/** Invisible, but makes a repeated announcement a different string. */
const NBSP = String.fromCharCode(0xa0)

/**
 * One room's floor plan — the map and, for an Administrator who may edit it,
 * placement (WP-D) and, since fix pass 2, creating the plan itself.
 *
 * **Fails closed on the server's answer.** The route guard has already checked
 * the role and permission, but that is only a courtesy: the backend refuses
 * everyone but an Administrator, and a 403 from it — for any reason, including
 * a stale or tampered client — replaces the page with the Forbidden screen. No
 * plan data is ever rendered from a failed response. Editing is offered only
 * when the server says so (`editor.can_edit`), and every write is authorized
 * again when it arrives.
 */
export default function FloorPlanRoomPage() {
  const { id } = useParams<{ id: string }>()
  const { data: plan, isLoading, error } = useRoomPlan(id)

  useDocumentMeta({ title: plan ? `${plan.room.name} floor plan` : 'Floor plan' })

  if (isLoading) return <PageLoader />

  if (isAxiosError(error) && (error.response?.status === 403 || error.response?.status === 401)) {
    return <ForbiddenPage />
  }

  if (isAxiosError(error) && error.response?.status === 404) {
    return <Alert tone="error">That room could not be found. It may have been archived.</Alert>
  }

  if (error || !plan || !id) {
    return <Alert tone="error">We couldn’t load this floor plan.</Alert>
  }

  return <RoomPlanView roomId={id} plan={plan} />
}

function RoomPlanView({ roomId, plan }: { roomId: string; plan: RoomPlan }) {
  const place = usePlacePcUnit(roomId)
  const createLayout = useCreateLayout(roomId)
  const cardTitleId = useId()
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [saving, setSaving] = useState<ReadonlySet<string>>(new Set())
  const [placeError, setPlaceError] = useState<string | null>(null)
  const [announcement, setAnnouncement] = useState('')
  const [arranging, setArranging] = useState(false)
  const [newVersionOpen, setNewVersionOpen] = useState(false)
  // WP-G — which unit's maintenance history is open, if any. Independent of
  // `selectedId` (which drives move targeting in edit mode): selecting a node
  // to move it also opens its history, but closing the inspector must never
  // clear a move in progress, so the two are tracked separately.
  const [inspectingId, setInspectingId] = useState<string | null>(null)

  const { layout } = plan
  const editable = plan.editor.can_edit && layout !== null
  const where = [plan.room.building?.name, plan.room.floor?.name].filter(Boolean).join(' · ')
  const alerts = plan.pcs.filter(
    (pc) => pc.open_ticket !== null || ALERT_STATUSES.has(pc.status.value),
  ).length

  /**
   * Polite, never assertive: a committed move is news, not an interruption. A
   * repeated sentence gets an invisible trailing space so a screen reader still
   * reads it — some ignore an unchanged live region.
   */
  const announce = (message: string) =>
    setAnnouncement((current) => (current === message ? `${message}${NBSP}` : message))

  const markSaving = (pcId: string, on: boolean) =>
    setSaving((current) => {
      const next = new Set(current)
      if (on) next.add(pcId)
      else next.delete(pcId)
      return next
    })

  // WP-E — live updates from every other viewer of this room (FR-FP-007). Own
  // in-flight moves are skipped: their own onSuccess is the authoritative
  // reconciliation, so an echo of the same move is a no-op rather than a race.
  useFloorPlanChannel(roomId, {
    layoutVersion: layout?.version,
    isOwnPendingMove: (pcId) => saving.has(pcId),
    announce,
  })

  /** The one write path — drag, keyboard move, numeric form, "Place on plan" and "Arrange" all end here. */
  const placeUnit = async (pcId: string, point: Point, snap: boolean): Promise<PlacedPc> => {
    if (!layout) throw new Error('No active layout.')
    const placed = plan.pcs.find((pc) => pc.id === pcId)
    const unit = placed ?? plan.unplaced.find((pc) => pc.id === pcId)
    const name = unit?.name ?? 'The unit'

    setPlaceError(null)
    markSaving(pcId, true)
    try {
      const stored = await place.mutateAsync({
        version: layout.version,
        pcId,
        // WP-F, D3: echo what this browser currently believes the position
        // is. A unit not yet on the plan (`placed` undefined) has nothing to
        // echo — null, so the write proceeds as a first placement.
        request: { x: point.x, y: point.y, snap, expected_updated_at: placed?.updated_at ?? null },
        preview: previewPoint(point, layout, snap),
      })
      // Say where it actually is — the server's point, which may have been
      // snapped or clamped away from the one that was sent.
      const adjusted = stored.x !== point.x || stored.y !== point.y
      const how = adjusted ? (snap ? ', aligned to the grid' : ', kept inside the room') : ''
      announce(`${stored.name} placed at ${describePoint(stored)}${how}.`)
      return stored
    } catch (failure) {
      const message = placementErrorMessage(failure)
      setPlaceError(message)
      announce(`${name} could not be moved. ${message}`)
      throw failure
    } finally {
      markSaving(pcId, false)
    }
  }

  const placeFromMap = (pc: PlacedPc, point: Point, snap: boolean) => {
    // Refusals are already shown and announced; nothing else to do here.
    placeUnit(pc.id, point, snap).catch(() => undefined)
  }

  const spotFor = (placed: PlacedPc[]): Point => {
    if (!layout) return { x: 0, y: 0 }
    return (
      freeSpot(placed, layout, nodeMetrics(layout).radius * 3) ?? {
        x: Math.round(layout.width / 2),
        y: Math.round(layout.height / 2),
      }
    )
  }

  const placeUnplaced = (pcId: string) => {
    if (!layout) return
    setSelectedId(pcId)
    placeUnit(pcId, spotFor(plan.pcs), true).catch(() => undefined)
  }

  /**
   * Put every unplaced unit on the grid, in rows, one write at a time. Each
   * placement goes through the same authorized, versioned call as a drag, and
   * each next spot is chosen against the units placed so far (including the
   * server's answer for the previous one), so nothing lands on top of
   * anything. Stops at the first refusal — it is already shown.
   */
  const arrangeUnplaced = async () => {
    if (!layout || arranging) return
    setArranging(true)
    try {
      let placed = [...plan.pcs]
      for (const unit of plan.unplaced) {
        const stored = await placeUnit(unit.id, spotFor(placed), true)
        placed = [...placed, stored]
      }
      announce(
        `${plan.unplaced.length} ${plan.unplaced.length === 1 ? 'unit' : 'units'} arranged on the plan.`,
      )
    } catch {
      // Announced and shown by placeUnit.
    } finally {
      setArranging(false)
    }
  }

  const startLayout = async (request: LayoutCreateRequest) => {
    await createLayout.mutateAsync(request)
    setNewVersionOpen(false)
    announce('A new layout is active. Units can now be placed on it.')
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-center gap-2 text-sm">
        <Link
          to="/app/floor-plan"
          className="inline-flex items-center gap-1.5 rounded-sm text-muted hover:text-ink"
        >
          <ArrowLeft size={15} aria-hidden="true" />
          Floor plan
        </Link>
      </div>

      <header className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-[-0.02em] text-ink-strong">
            {plan.room.name}
          </h1>
          <p className="mt-1 text-sm text-muted">
            {where || plan.room.code}
            {layout && (
              <>
                {' · '}
                <span className="tnum">
                  Layout v{layout.version}, {layout.width} × {layout.height}
                </span>
              </>
            )}
          </p>
        </div>

        {editable && (
          <div className="flex flex-wrap items-center gap-2">
            {plan.unplaced.length > 0 && (
              <Button
                variant="primary"
                size="sm"
                leftIcon={<Rows3 size={16} aria-hidden="true" />}
                onClick={() => void arrangeUnplaced()}
                loading={arranging}
              >
                Arrange{' '}
                {plan.unplaced.length === 1
                  ? 'the unplaced unit'
                  : `${plan.unplaced.length} unplaced units`}
              </Button>
            )}
            <Button
              variant="secondary"
              size="sm"
              leftIcon={<LayoutTemplate size={16} aria-hidden="true" />}
              onClick={() => setNewVersionOpen(true)}
            >
              New layout version
            </Button>
          </div>
        )}
      </header>

      {/* Movement is announced here (NFR-ACC-004). Always rendered, so the
          region exists before the first message and is actually read. */}
      <p role="status" aria-live="polite" className="sr-only" data-testid="floor-plan-announcer">
        {announcement}
      </p>

      {layout === null ? (
        plan.editor.can_edit ? (
          <Surface className="max-w-2xl p-6">
            <h2 className="text-base font-semibold text-ink-strong">
              Create this room’s floor plan
            </h2>
            <p className="mt-1 text-sm text-muted">
              {plan.unplaced_count > 0
                ? `${plan.unplaced_count} ${plan.unplaced_count === 1 ? 'unit is' : 'units are'} in this room and will be ready to place as soon as the plan exists.`
                : 'Choose the room’s size. Units can be placed once they are registered to this room.'}
            </p>
            <div className="mt-5">
              <CreateLayoutForm onSubmit={startLayout} />
            </div>
          </Surface>
        ) : (
          <EmptyState
            icon={<LayoutGrid size={22} />}
            title="No floor plan for this room yet"
            description={
              plan.unplaced_count > 0
                ? `${plan.unplaced_count} ${plan.unplaced_count === 1 ? 'unit is' : 'units are'} in this room, but the room has no active layout to place them on.`
                : 'This room has no active layout and no units.'
            }
          />
        )
      ) : (
        <>
          {!editable && plan.unplaced_count > 0 && (
            <Alert tone="info">
              {plan.unplaced_count}{' '}
              {plan.unplaced_count === 1 ? 'unit in this room is' : 'units in this room are'} not
              placed on the plan yet.
            </Alert>
          )}

          {placeError && (
            <Alert tone="error" title="That move was not saved">
              {placeError}
            </Alert>
          )}

          <FloorPlanToolbar />

          <section
            aria-labelledby={cardTitleId}
            className="overflow-hidden rounded-lg border border-border bg-surface"
          >
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b border-border px-4 py-2.5">
              <h2 id={cardTitleId} className="text-sm font-semibold text-ink-strong">
                {plan.room.name}
                {plan.room.floor ? ` — ${plan.room.floor.name}` : ''}
              </h2>
              <span
                className="slashed-zero font-mono text-[11px] text-muted"
                data-testid="floor-plan-summary"
              >
                {plan.pcs.length} {plan.pcs.length === 1 ? 'unit' : 'units'} · {alerts}{' '}
                {alerts === 1 ? 'alert' : 'alerts'}
              </span>
            </div>

            <FloorPlanCanvas
              key={`${plan.room.id}:${layout.version}`}
              roomName={plan.room.name}
              layout={layout}
              pcs={plan.pcs}
              editable={editable}
              snapToGrid={plan.editor.snap_to_grid}
              selectedId={selectedId}
              onSelect={setSelectedId}
              onPlace={placeFromMap}
              savingIds={saving}
              announce={announce}
              framed={false}
            />

            {plan.pcs.length > 0 && (
              <div className="border-t border-border px-4 py-3">
                <FloorPlanLegend pcs={plan.pcs} />
              </div>
            )}
          </section>

          {editable && (
            <>
              <UnplacedUnits
                units={plan.unplaced}
                onPlace={(unit) => placeUnplaced(unit.id)}
                placingId={plan.unplaced.find((unit) => saving.has(unit.id))?.id ?? null}
              />
              <PlacementPanel
                layout={layout}
                pcs={plan.pcs}
                unplaced={plan.unplaced}
                selectedId={selectedId}
                onSelect={setSelectedId}
                snapDefault={plan.editor.snap_to_grid}
                onPlace={placeUnit}
              />
            </>
          )}

          <PcUnitList roomName={plan.room.name} pcs={plan.pcs} onInspect={setInspectingId} />

          {inspectingId && (
            <PcInspectorPanel pcId={inspectingId} onClose={() => setInspectingId(null)} />
          )}

          <ConfirmDialog
            open={newVersionOpen}
            onClose={() => setNewVersionOpen(false)}
            onConfirm={() =>
              (
                document.getElementById('new-layout-version') as HTMLFormElement | null
              )?.requestSubmit()
            }
            title="Start a new layout version"
            description="The new version becomes the active plan with no units on it — positions never carry over between versions, so the current arrangement stays with the current version. You can arrange the units again afterwards."
            confirmLabel="Create and activate"
            loading={createLayout.isPending}
          >
            <CreateLayoutForm id="new-layout-version" onSubmit={startLayout} hideSubmit />
          </ConfirmDialog>
        </>
      )}
    </div>
  )
}

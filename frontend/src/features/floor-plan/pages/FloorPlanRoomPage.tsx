import { isAxiosError } from 'axios'
import { ArrowLeft, LayoutGrid } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { Alert, EmptyState, PageLoader } from '@/components/ui'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import ForbiddenPage from '@/pages/ForbiddenPage'
import { FloorPlanCanvas } from '../components/FloorPlanCanvas'
import { FloorPlanLegend } from '../components/FloorPlanLegend'
import { FloorPlanToolbar } from '../components/FloorPlanToolbar'
import { PcUnitList } from '../components/PcUnitList'
import { PlacementPanel } from '../components/PlacementPanel'
import { UnplacedUnits } from '../components/UnplacedUnits'
import { usePlacePcUnit } from '../hooks/mutations'
import { useRoomPlan } from '../hooks/queries'
import { placementErrorMessage } from '../lib/errors'
import { describePoint, freeSpot, nodeMetrics, previewPoint, type Point } from '../lib/placement'
import type { PlacedPc, RoomPlan } from '../types'

/** Invisible, but makes a repeated announcement a different string. */
const NBSP = String.fromCharCode(0xa0)

/**
 * One room's floor plan — the map and, for an Administrator who may edit it,
 * placement (WP-D).
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
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [saving, setSaving] = useState<ReadonlySet<string>>(new Set())
  const [placeError, setPlaceError] = useState<string | null>(null)
  const [announcement, setAnnouncement] = useState('')

  const { layout } = plan
  const editable = plan.editor.can_edit && layout !== null
  const where = [plan.room.building?.name, plan.room.floor?.name].filter(Boolean).join(' · ')

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

  /** The one write path — drag, keyboard move, numeric form and "Place on plan" all end here. */
  const placeUnit = async (pcId: string, point: Point, snap: boolean): Promise<PlacedPc> => {
    if (!layout) throw new Error('No active layout.')
    const unit = plan.pcs.find((pc) => pc.id === pcId) ?? plan.unplaced.find((pc) => pc.id === pcId)
    const name = unit?.name ?? 'The unit'

    setPlaceError(null)
    markSaving(pcId, true)
    try {
      const stored = await place.mutateAsync({
        version: layout.version,
        pcId,
        request: { x: point.x, y: point.y, snap },
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

  const placeUnplaced = (pcId: string) => {
    if (!layout) return
    const spot = freeSpot(plan.pcs, layout, nodeMetrics(layout).radius * 3) ?? {
      x: Math.round(layout.width / 2),
      y: Math.round(layout.height / 2),
    }
    setSelectedId(pcId)
    placeUnit(pcId, spot, true).catch(() => undefined)
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

      <header>
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
      </header>

      {/* Movement is announced here (NFR-ACC-004). Always rendered, so the
          region exists before the first message and is actually read. */}
      <p role="status" aria-live="polite" className="sr-only" data-testid="floor-plan-announcer">
        {announcement}
      </p>

      {layout === null ? (
        <EmptyState
          icon={<LayoutGrid size={22} />}
          title="No floor plan for this room yet"
          description={
            plan.unplaced_count > 0
              ? `${plan.unplaced_count} ${plan.unplaced_count === 1 ? 'unit is' : 'units are'} in this room, but the room has no active layout to place them on.`
              : 'This room has no active layout and no units.'
          }
        />
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
          />

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

          <FloorPlanLegend pcs={plan.pcs} />
          <PcUnitList roomName={plan.room.name} pcs={plan.pcs} />
        </>
      )}
    </div>
  )
}

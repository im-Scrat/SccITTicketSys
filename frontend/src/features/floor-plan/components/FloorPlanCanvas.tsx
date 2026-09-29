import { useEffect, useId, useRef, useState, type KeyboardEvent, type PointerEvent } from 'react'
import { cn } from '@/lib/cn'
import {
  ARROW_DIRECTIONS,
  describePoint,
  nodeLabel,
  nodeMetrics,
  previewPoint,
  stepPoint,
  type Point,
} from '../lib/placement'
import { toViewBox, visibleSize } from '../lib/viewport'
import { useViewportStore } from '../stores/useViewportStore'
import type { PlacedPc, PlanLayout } from '../types'
import { PcMarker } from './PcMarker'

/**
 * The floor-plan map, drawn as SVG.
 *
 * SVG rather than canvas: at this scale (a handful of machines per room) it
 * costs nothing, and it keeps every node a real element that can carry an
 * accessible name, take focus, and be tested. The coordinate system is the
 * layout's own pixels, so a stored `pos_x` is used as-is — no conversion, and
 * nothing to disagree with the server's snapping.
 *
 * **View controls** (always), all doing the same thing to the same store:
 *  - drag the background with a mouse or pen to pan; Ctrl/⌘ + wheel to zoom
 *  - with the map focused: arrow keys pan, `+` / `-` zoom, `0` resets
 *  - the toolbar's buttons — the way to pan and zoom on a touch screen
 *
 * **Placement** (only when `editable`), three routes to the same write:
 *  - drag a unit (pointer capture keeps the drag even off the map); Shift while
 *    dragging places it off the grid
 *  - focus a unit, Enter for move mode, arrows one grid cell (Shift + arrow one
 *    pixel), Enter to place, Escape to put it back — no dragging needed
 *    (WCAG 2.5.7, FR-FP-009)
 *  - the numeric form beside the map (`PlacementPanel`)
 *
 * The map never decides where a unit ends up. A drag or a move only *previews*
 * a point (the server's own snap-then-clamp rule, so the preview rarely jumps);
 * `onPlace` sends it, and the plan the parent passes back in is the server's
 * answer.
 *
 * **Touch.** A finger on the background is left to the browser, so the page
 * scrolls and pinch-zooms normally and the map never traps a swipe; the
 * toolbar pans and zooms the map. Only an editable unit claims a touch
 * (`touch-action: none` on the node itself), so a finger can drag it.
 */

const KEY_PAN: Record<string, [number, number]> = {
  ArrowLeft: [-1, 0],
  ArrowRight: [1, 0],
  ArrowUp: [0, -1],
  ArrowDown: [0, 1],
}

/** Screen pixels a pointer must travel before a press on a unit becomes a drag. */
const DRAG_THRESHOLD = 4

type Gesture =
  | { kind: 'keyboard'; id: string; origin: Point; point: Point; fine: boolean }
  | {
      kind: 'drag'
      id: string
      pointerId: number
      startX: number
      startY: number
      origin: Point
      point: Point
      moved: boolean
      snap: boolean
    }

interface FloorPlanCanvasProps {
  roomName: string
  layout: PlanLayout
  pcs: PlacedPc[]
  /** Offer placement. The server authorizes every write regardless. */
  editable?: boolean
  /** Snap a drag to the grid unless Shift is held (`floor_plan.snap_to_grid`). */
  snapToGrid?: boolean
  selectedId?: string | null
  onSelect?: (pcId: string) => void
  /** A unit was dropped or placed from the keyboard. */
  onPlace?: (pc: PlacedPc, point: Point, snap: boolean) => void
  /** Units whose placement is waiting for the server's answer. */
  savingIds?: ReadonlySet<string>
  /** Say something in the page's polite live region. */
  announce?: (message: string) => void
}

export function FloorPlanCanvas({
  roomName,
  layout,
  pcs,
  editable = false,
  snapToGrid = true,
  selectedId = null,
  onSelect,
  onPlace,
  savingIds,
  announce,
}: FloorPlanCanvasProps) {
  const uid = useId().replace(/[^a-zA-Z0-9_-]/g, '')
  const gridId = `fp-grid-${uid}`
  const helpId = `fp-help-${uid}`
  const nodeHelpId = `fp-node-help-${uid}`

  const zoom = useViewportStore((s) => s.zoom)
  const x = useViewportStore((s) => s.x)
  const y = useViewportStore((s) => s.y)
  const setContent = useViewportStore((s) => s.setContent)
  const reset = useViewportStore((s) => s.reset)

  const frameRef = useRef<HTMLDivElement>(null)
  const svgRef = useRef<SVGSVGElement>(null)
  const pan = useRef<{ pointerId: number; x: number; y: number } | null>(null)
  const [panning, setPanning] = useState(false)

  // The in-progress move, if any. Mirrored in a ref so pointer handlers that
  // fire in quick succession always see the latest value.
  const [gesture, setGestureState] = useState<Gesture | null>(null)
  const gestureRef = useRef<Gesture | null>(null)
  const setGesture = (next: Gesture | null) => {
    gestureRef.current = next
    setGestureState(next)
  }

  const content = { width: layout.width, height: layout.height }

  // A new room or layout version starts from the whole plan, never from the
  // previous one's zoom.
  useEffect(() => {
    setContent({ width: layout.width, height: layout.height })
    reset()
  }, [layout.width, layout.height, layout.version, setContent, reset])

  // Ctrl/⌘ + wheel zooms about the pointer. Registered natively and non-passive
  // because React's own wheel handler is passive and cannot preventDefault —
  // without it the browser would zoom the whole page as well.
  useEffect(() => {
    const frame = frameRef.current
    if (!frame) return

    const onWheel = (event: WheelEvent) => {
      if (!event.ctrlKey && !event.metaKey) return
      event.preventDefault()

      const rect = frame.getBoundingClientRect()
      const focus = {
        u: rect.width > 0 ? (event.clientX - rect.left) / rect.width : 0.5,
        v: rect.height > 0 ? (event.clientY - rect.top) / rect.height : 0.5,
      }
      const store = useViewportStore.getState()
      store.zoomTo(store.zoom * Math.exp(-event.deltaY * 0.002), focus)
    }

    frame.addEventListener('wheel', onWheel, { passive: false })
    return () => frame.removeEventListener('wheel', onWheel)
  }, [])

  // Escape abandons a drag wherever focus happens to be.
  useEffect(() => {
    if (gesture?.kind !== 'drag') return

    const onKey = (event: globalThis.KeyboardEvent) => {
      if (event.key !== 'Escape') return
      event.preventDefault()
      cancelGesture()
    }

    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
    // cancelGesture reads refs only; re-binding per gesture kind is enough.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [gesture?.kind])

  // A unit that leaves the plan mid-move (another administrator, a transfer)
  // takes its move with it.
  useEffect(() => {
    const active = gestureRef.current
    if (active && !pcs.some((pc) => pc.id === active.id)) setGesture(null)
  }, [pcs])

  /** Layout units per screen pixel at the current zoom. */
  const unitsPerPixel = (): number => {
    const rect = svgRef.current?.getBoundingClientRect()
    if (!rect || rect.width === 0 || rect.height === 0) return 0
    const shown = visibleSize(content, useViewportStore.getState().zoom)
    return 1 / Math.min(rect.width / shown.width, rect.height / shown.height)
  }

  const findPc = (id: string) => pcs.find((pc) => pc.id === id)

  function cancelGesture() {
    const active = gestureRef.current
    if (!active) return
    setGesture(null)

    const pc = findPc(active.id)
    if (pc && (active.kind === 'keyboard' || active.moved)) {
      announce?.(`Move cancelled. ${pc.name} stays at ${describePoint(active.origin)}.`)
    }
  }

  function commit(active: Gesture, snap: boolean) {
    setGesture(null)
    const pc = findPc(active.id)
    if (!pc) return

    if (active.point.x === active.origin.x && active.point.y === active.origin.y) {
      announce?.(`${pc.name} was not moved.`)
      return
    }

    onPlace?.(pc, active.point, snap)
  }

  // ── Background: pan the view ────────────────────────────────────────────

  const onPointerDown = (event: PointerEvent<SVGSVGElement>) => {
    // A finger scrolls the page (see "Touch" above); the toolbar pans the map.
    if (event.pointerType === 'touch') return
    if (event.pointerType === 'mouse' && event.button !== 0) return
    event.currentTarget.setPointerCapture(event.pointerId)
    pan.current = { pointerId: event.pointerId, x: event.clientX, y: event.clientY }
    setPanning(true)
  }

  const onPointerMove = (event: PointerEvent<SVGSVGElement>) => {
    const active = pan.current
    if (!active || active.pointerId !== event.pointerId) return

    const scale = unitsPerPixel()
    useViewportStore
      .getState()
      .panByUnits(-(event.clientX - active.x) * scale, -(event.clientY - active.y) * scale)
    pan.current = { ...active, x: event.clientX, y: event.clientY }
  }

  const endPan = (event: PointerEvent<SVGSVGElement>) => {
    if (pan.current?.pointerId !== event.pointerId) return
    pan.current = null
    setPanning(false)
  }

  const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    // Leave browser and assistive-technology shortcuts alone.
    if (event.ctrlKey || event.metaKey || event.altKey) return

    const store = useViewportStore.getState()
    const direction = KEY_PAN[event.key]

    if (direction) store.panByStep(direction[0], direction[1])
    else if (event.key === '+' || event.key === '=') store.zoomIn()
    else if (event.key === '-' || event.key === '_') store.zoomOut()
    else if (event.key === '0') store.reset()
    else return

    event.preventDefault()
  }

  // ── A unit: select, drag, keyboard move ─────────────────────────────────

  const onNodePointerDown = (pc: PlacedPc, event: PointerEvent<SVGGElement>) => {
    if (!editable) return
    if (event.pointerType === 'mouse' && event.button !== 0) return

    // The press belongs to the unit, not to the background's pan.
    event.stopPropagation()
    event.currentTarget.setPointerCapture(event.pointerId)

    setGesture({
      kind: 'drag',
      id: pc.id,
      pointerId: event.pointerId,
      startX: event.clientX,
      startY: event.clientY,
      origin: { x: pc.x, y: pc.y },
      point: { x: pc.x, y: pc.y },
      moved: false,
      snap: snapToGrid && !event.shiftKey,
    })
  }

  const onNodePointerMove = (event: PointerEvent<SVGGElement>) => {
    const active = gestureRef.current
    if (active?.kind !== 'drag' || active.pointerId !== event.pointerId) return
    event.stopPropagation()

    const dx = event.clientX - active.startX
    const dy = event.clientY - active.startY
    if (!active.moved && Math.hypot(dx, dy) < DRAG_THRESHOLD) return

    const scale = unitsPerPixel()
    const snap = snapToGrid && !event.shiftKey
    const point = previewPoint(
      { x: active.origin.x + dx * scale, y: active.origin.y + dy * scale },
      layout,
      snap,
    )

    setGesture({ ...active, moved: true, point, snap })
  }

  const onNodePointerUp = (pc: PlacedPc, event: PointerEvent<SVGGElement>) => {
    const active = gestureRef.current
    if (active?.kind !== 'drag' || active.pointerId !== event.pointerId) return
    event.stopPropagation()

    if (active.moved) {
      commit(active, active.snap)
    } else {
      // A press without travel is a click: select the unit.
      setGesture(null)
      onSelect?.(pc.id)
    }
  }

  const onNodePointerCancel = (event: PointerEvent<SVGGElement>) => {
    const active = gestureRef.current
    if (active?.kind !== 'drag' || active.pointerId !== event.pointerId) return
    cancelGesture()
  }

  const onNodeKeyDown = (pc: PlacedPc, event: KeyboardEvent<SVGGElement>) => {
    if (!editable) return
    if (event.ctrlKey || event.metaKey || event.altKey) return

    const active = gestureRef.current
    const moving = active?.kind === 'keyboard' && active.id === pc.id

    if (!moving) {
      if (event.key !== 'Enter' && event.key !== ' ') return
      event.preventDefault()
      event.stopPropagation()

      onSelect?.(pc.id)
      setGesture({
        kind: 'keyboard',
        id: pc.id,
        origin: { x: pc.x, y: pc.y },
        point: { x: pc.x, y: pc.y },
        fine: false,
      })
      announce?.(
        `Moving ${pc.name} from ${describePoint({ x: pc.x, y: pc.y })}. ` +
          'Arrow keys move one grid cell, Shift and an arrow key one pixel. ' +
          'Enter places it, Escape cancels.',
      )
      return
    }

    const direction = ARROW_DIRECTIONS[event.key]

    if (direction) {
      event.preventDefault()
      event.stopPropagation()
      const point = stepPoint(active.point, direction, layout, event.shiftKey)
      setGesture({ ...active, point, fine: active.fine || event.shiftKey })
      announce?.(describePoint(point))
      return
    }

    if (event.key === 'Enter') {
      event.preventDefault()
      event.stopPropagation()
      // Any pixel step asks for free placement, or the server would snap the
      // fine adjustment straight back to the grid.
      commit(active, !active.fine)
      return
    }

    if (event.key === 'Escape') {
      event.preventDefault()
      event.stopPropagation()
      cancelGesture()
      return
    }

    // Space would scroll the page mid-move; everything else is left alone.
    if (event.key === ' ') {
      event.preventDefault()
      event.stopPropagation()
    }
  }

  const onNodeBlur = (pc: PlacedPc) => {
    const active = gestureRef.current
    if (active?.kind === 'keyboard' && active.id === pc.id) cancelGesture()
  }

  const { radius, nameSize, statusSize } = nodeMetrics(layout)

  /*
   * The lifted unit must paint above the others, but its element must never
   * move in the DOM: re-parenting or re-ordering it releases the pointer
   * capture a drag depends on (the browser fires `lostpointercapture` when a
   * captured element leaves the document, even for an instant) and drops
   * keyboard focus mid-move. So the nodes keep their order, and an inert
   * `<use>` copy of the lifted one is painted last, on top.
   */
  const nodeId = (pcId: string) => `fp-node-${uid}-${pcId.replace(/[^a-zA-Z0-9_-]/g, '')}`
  const liftedId =
    gesture && (gesture.kind === 'keyboard' || gesture.moved) ? nodeId(gesture.id) : null

  return (
    <div>
      <div
        ref={frameRef}
        tabIndex={0}
        role="group"
        aria-label={`Floor plan of ${roomName}, ${pcs.length} ${pcs.length === 1 ? 'unit' : 'units'} placed`}
        aria-describedby={helpId}
        onKeyDown={onKeyDown}
        className="overflow-hidden rounded-md border border-border bg-surface-sunken"
      >
        <svg
          ref={svgRef}
          viewBox={toViewBox({ zoom, x, y }, content)}
          preserveAspectRatio="xMidYMid meet"
          onPointerDown={onPointerDown}
          onPointerMove={onPointerMove}
          onPointerUp={endPan}
          onPointerCancel={endPan}
          className={cn(
            'block h-auto max-h-[70vh] w-full touch-manipulation select-none',
            panning ? 'cursor-grabbing' : 'cursor-grab',
          )}
          style={{ aspectRatio: `${layout.width} / ${layout.height}` }}
          data-testid="floor-plan-svg"
        >
          <defs>
            <pattern
              id={gridId}
              width={layout.grid_size}
              height={layout.grid_size}
              patternUnits="userSpaceOnUse"
            >
              <path
                d={`M ${layout.grid_size} 0 H 0 V ${layout.grid_size}`}
                fill="none"
                stroke="var(--grid-line)"
                strokeWidth={1}
                vectorEffect="non-scaling-stroke"
              />
            </pattern>
          </defs>

          {/* The room: its floor, the grid over it, and its boundary. */}
          <rect
            x={0}
            y={0}
            width={layout.width}
            height={layout.height}
            className="fill-surface"
            data-testid="floor-plan-floor"
          />
          <rect
            x={0}
            y={0}
            width={layout.width}
            height={layout.height}
            fill={`url(#${gridId})`}
            data-testid="floor-plan-grid"
          />
          <rect
            x={0}
            y={0}
            width={layout.width}
            height={layout.height}
            fill="none"
            className="stroke-control-border"
            strokeWidth={2}
            vectorEffect="non-scaling-stroke"
            data-testid="floor-plan-boundary"
          />

          {pcs.map((pc) => {
            const active = gesture?.id === pc.id ? gesture : null
            const point = active ? active.point : { x: pc.x, y: pc.y }
            const lifted = active !== null && (active.kind === 'keyboard' || active.moved)
            const selected = selectedId === pc.id
            const saving = savingIds?.has(pc.id) ?? false

            return (
              <g
                key={pc.id}
                id={nodeId(pc.id)}
                role={editable ? 'button' : 'img'}
                tabIndex={editable ? 0 : undefined}
                aria-label={nodeLabel(pc, point)}
                aria-describedby={editable ? nodeHelpId : undefined}
                aria-busy={saving || undefined}
                transform={`translate(${point.x} ${point.y})`}
                data-testid="floor-plan-node"
                data-status={pc.status.value}
                data-moving={lifted || undefined}
                data-selected={selected || undefined}
                className={cn(editable && 'group cursor-move outline-none', saving && 'opacity-70')}
                style={editable ? { touchAction: 'none' } : undefined}
                onPointerDown={(event) => onNodePointerDown(pc, event)}
                onPointerMove={onNodePointerMove}
                onPointerUp={(event) => onNodePointerUp(pc, event)}
                onPointerCancel={onNodePointerCancel}
                onLostPointerCapture={onNodePointerCancel}
                onKeyDown={(event) => onNodeKeyDown(pc, event)}
                onBlur={() => onNodeBlur(pc)}
              >
                <title>{`${pc.name} — ${pc.status.label}`}</title>
                <g transform={pc.rotation ? `rotate(${pc.rotation})` : undefined}>
                  <PcMarker status={pc.status.value} tone={pc.status.tone} radius={radius} />
                </g>
                {/* Written labels sit outside the rotation so they stay upright. */}
                <text
                  y={radius + nameSize * 1.1}
                  textAnchor="middle"
                  fontSize={nameSize}
                  fontWeight={600}
                  className="fill-ink-strong"
                  style={{ paintOrder: 'stroke', stroke: 'var(--color-surface)', strokeWidth: 3 }}
                >
                  {pc.name}
                </text>
                <text
                  y={radius + nameSize * 1.1 + statusSize * 1.25}
                  textAnchor="middle"
                  fontSize={statusSize}
                  className="fill-muted"
                  style={{ paintOrder: 'stroke', stroke: 'var(--color-surface)', strokeWidth: 3 }}
                >
                  {pc.status.label}
                </text>

                {editable && (
                  <>
                    {/* The lifted unit's drop shadow — the one shadow the
                        Flat-Plane Rule sanctions (a drag preview). */}
                    {lifted && (
                      <circle
                        r={radius * 1.35}
                        className="pointer-events-none fill-ink-strong/10"
                        data-testid="floor-plan-lift"
                      />
                    )}
                    {/* Selection: a solid ring. Keyboard focus: the focus ring,
                        drawn in SVG because an outline on a <g> is not
                        reliably painted by every browser. */}
                    {(selected || lifted) && (
                      <circle
                        r={radius + 6}
                        fill="none"
                        className="pointer-events-none stroke-primary"
                        strokeWidth={2}
                        strokeDasharray={lifted ? '5 4' : undefined}
                        vectorEffect="non-scaling-stroke"
                      />
                    )}
                    <circle
                      r={radius + 11}
                      fill="none"
                      className="pointer-events-none stroke-focus opacity-0 group-focus-visible:opacity-100"
                      strokeWidth={3}
                      vectorEffect="non-scaling-stroke"
                    />
                  </>
                )}
              </g>
            )
          })}

          {/* The lifted unit, painted again on top (see above). A picture of a
              node, not a node: no events, nothing for assistive technology. */}
          {liftedId && (
            <use
              href={`#${liftedId}`}
              aria-hidden="true"
              pointerEvents="none"
              data-testid="floor-plan-lift-overlay"
            />
          )}
        </svg>
      </div>

      <p id={helpId} className="mt-2 text-sm text-muted">
        Drag the map to pan with a mouse, or focus it and use the arrow keys. Press + and − to zoom,
        0 to reset. Hold Ctrl (⌘ on Mac) and scroll to zoom with a mouse. On a touch screen, use the
        buttons above to pan and zoom.
      </p>
      {editable && (
        <p id={nodeHelpId} className="mt-1 text-sm text-muted">
          To move a unit, drag it — hold Shift to place it off the grid. Or focus it and press
          Enter, move it with the arrow keys (Shift and an arrow key moves one pixel), then press
          Enter to place it or Escape to put it back. Exact coordinates can be entered below the
          map.
        </p>
      )}
    </div>
  )
}

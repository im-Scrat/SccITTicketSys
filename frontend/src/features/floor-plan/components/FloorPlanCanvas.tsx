import { useEffect, useId, useRef, useState, type KeyboardEvent, type PointerEvent } from 'react'
import { cn } from '@/lib/cn'
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
 * **Read-only.** Nothing here writes a position: no dragging of nodes, no
 * snapping, no persistence. Pointer dragging moves the *view*, not a machine.
 *
 * Controls, all doing the same thing to the same store:
 *  - drag the background to pan; Ctrl/⌘ + wheel (or pinch) to zoom
 *  - with the map focused: arrow keys pan, `+` / `-` zoom, `0` resets
 *  - the toolbar's buttons, for anyone not using the map's own keys
 *
 * Plain wheel scrolling is left alone, so the map never traps the page scroll.
 */

const KEY_PAN: Record<string, [number, number]> = {
  ArrowLeft: [-1, 0],
  ArrowRight: [1, 0],
  ArrowUp: [0, -1],
  ArrowDown: [0, 1],
}

interface FloorPlanCanvasProps {
  roomName: string
  layout: PlanLayout
  pcs: PlacedPc[]
}

export function FloorPlanCanvas({ roomName, layout, pcs }: FloorPlanCanvasProps) {
  const uid = useId().replace(/[^a-zA-Z0-9_-]/g, '')
  const gridId = `fp-grid-${uid}`
  const helpId = `fp-help-${uid}`

  const zoom = useViewportStore((s) => s.zoom)
  const x = useViewportStore((s) => s.x)
  const y = useViewportStore((s) => s.y)
  const setContent = useViewportStore((s) => s.setContent)
  const reset = useViewportStore((s) => s.reset)

  const frameRef = useRef<HTMLDivElement>(null)
  const svgRef = useRef<SVGSVGElement>(null)
  const drag = useRef<{ pointerId: number; x: number; y: number } | null>(null)
  const [dragging, setDragging] = useState(false)

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

  /** Layout units per screen pixel at the current zoom. */
  const unitsPerPixel = (): number => {
    const rect = svgRef.current?.getBoundingClientRect()
    if (!rect || rect.width === 0 || rect.height === 0) return 0
    const shown = visibleSize(content, useViewportStore.getState().zoom)
    return 1 / Math.min(rect.width / shown.width, rect.height / shown.height)
  }

  const onPointerDown = (event: PointerEvent<SVGSVGElement>) => {
    if (event.pointerType === 'mouse' && event.button !== 0) return
    event.currentTarget.setPointerCapture(event.pointerId)
    drag.current = { pointerId: event.pointerId, x: event.clientX, y: event.clientY }
    setDragging(true)
  }

  const onPointerMove = (event: PointerEvent<SVGSVGElement>) => {
    const active = drag.current
    if (!active || active.pointerId !== event.pointerId) return

    const scale = unitsPerPixel()
    useViewportStore
      .getState()
      .panByUnits(-(event.clientX - active.x) * scale, -(event.clientY - active.y) * scale)
    drag.current = { ...active, x: event.clientX, y: event.clientY }
  }

  const endDrag = (event: PointerEvent<SVGSVGElement>) => {
    if (drag.current?.pointerId !== event.pointerId) return
    drag.current = null
    setDragging(false)
  }

  const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    // Leave browser and assistive-technology shortcuts alone.
    if (event.ctrlKey || event.metaKey || event.altKey) return

    const store = useViewportStore.getState()
    const pan = KEY_PAN[event.key]

    if (pan) store.panByStep(pan[0], pan[1])
    else if (event.key === '+' || event.key === '=') store.zoomIn()
    else if (event.key === '-' || event.key === '_') store.zoomOut()
    else if (event.key === '0') store.reset()
    else return

    event.preventDefault()
  }

  // Node size follows the plan's size, so a 1900px-wide lab and a 600px-wide
  // office both draw machines at a readable proportion.
  const unit = Math.max(24, Math.round(Math.max(layout.width, layout.height) / 40))
  const radius = unit * 0.8
  const nameSize = unit * 0.55
  const statusSize = unit * 0.45

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
          onPointerUp={endDrag}
          onPointerCancel={endDrag}
          className={cn(
            'block h-auto max-h-[70vh] w-full touch-none select-none',
            dragging ? 'cursor-grabbing' : 'cursor-grab',
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

          {pcs.map((pc) => (
            <g
              key={pc.id}
              role="img"
              aria-label={`${pc.name}, ${pc.status.label}, position ${pc.x} by ${pc.y}`}
              transform={`translate(${pc.x} ${pc.y})`}
              data-testid="floor-plan-node"
              data-status={pc.status.value}
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
            </g>
          ))}
        </svg>
      </div>

      <p id={helpId} className="mt-2 text-sm text-muted">
        Drag the map to pan, or focus it and use the arrow keys. Press + and − to zoom, 0 to reset.
        Hold Ctrl (⌘ on Mac) and scroll to zoom with a mouse. The buttons above do the same.
      </p>
    </div>
  )
}

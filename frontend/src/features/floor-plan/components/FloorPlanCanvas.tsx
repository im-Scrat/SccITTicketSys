import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react'
import { cn } from '@/lib/cn'
import { DRAG_MIME, type DragItem } from '../lib/dragItem'
import { FIXTURES, inDrawOrder } from '../lib/fixtures'
import {
  clientToLayout,
  corners,
  halfExtent,
  normalizeRotation,
  overlapsAny,
  pointsInsideRoom,
  round2,
  snapAndClamp,
  snapValue,
  unitsPerPixel,
  type Point,
  type ViewBox,
} from '../lib/geometry'
import type { LayoutDoc, ObjectKey, WorkstationGeom } from '../lib/layoutDocument'
import { visibleSize } from '../lib/viewport'
import { useEditor, useEditorStore } from '../stores/useEditor'
import type { Fixture, PcStatus, RoomPlan } from '../types'
import { equipmentRoster, isEquipmentId } from '../lib/equipment'
import { EquipmentNode } from './EquipmentNode'
import { FixtureShape } from './FixtureShape'
import { PcNode } from './PcNode'

/** Travel, in CSS pixels, before a press becomes a drag rather than a click. */
const DRAG_THRESHOLD_PX = 4
/** Minimum fixture side, in layout units. */
const MIN_FIXTURE = 4

interface PcMeta {
  name: string
  unit_code: string
  status: PcStatus
  openTicket: string | null
}

type Gesture =
  | {
      kind: 'pan'
      pointerId: number
      last: { x: number; y: number }
      start: { x: number; y: number }
      moved: boolean
    }
  | {
      kind: 'move'
      pointerId: number
      start: Point
      startClient: { x: number; y: number }
      origins: Partial<Record<ObjectKey, Point>>
      primary: ObjectKey
      moved: boolean
    }
  | { kind: 'resize'; pointerId: number; key: ObjectKey; sx: 1 | -1; sy: 1 | -1; origin: Fixture }
  | { kind: 'rotate'; pointerId: number; key: ObjectKey; origin: Fixture }

interface FloorPlanCanvasProps {
  plan: RoomPlan
  doc: LayoutDoc
  editing: boolean
  /** When set, workstations whose status is not in it are dimmed (the header's status chips). */
  highlight: ReadonlySet<string> | null
  onAnnounce: (message: string) => void
  onDropItem: (item: DragItem, point: Point) => void
  /** A workstation or fixture was chosen by click or Enter — open its details. */
  onActivate: (key: ObjectKey) => void
}

/**
 * The Floorplan's SVG canvas (Phase 2.11, SDD DD-102).
 *
 * **Coordinates.** The viewBox is a window onto the room in layout units; a
 * pointer is converted with `clientToLayout` (bounding rect + viewBox, the
 * `xMidYMid meet` arithmetic), so snapping and bounds are decided in layout
 * units and behave the same at every zoom and screen size.
 *
 * **Gestures.** One finger or the mouse on the background pans; two fingers
 * pinch-zoom; Ctrl/⌘ + wheel zooms about the pointer, the wheel alone pans.
 * In edit mode, pressing an object selects it (Shift adds or removes) and
 * dragging moves the whole selection as one, snapped by its first object's
 * bounding box (hold Alt to place freely) and kept inside the room. A move
 * that would put two workstations on top of each other is cancelled and
 * announced. A selected fixture shows corner handles (resize, snapped to the
 * grid) and a turn handle (15° steps, Alt for free). Every gesture is drawn
 * from local preview state — no request, and no draft change, until the
 * pointer is released, which commits one undo step.
 *
 * Touch claims the canvas (`touch-action: none`): the workspace is a fixed
 * viewport-height panel, so there is no page scroll for it to trap — this
 * deliberately replaces WP-D's "a finger on the background scrolls the page".
 */
export function FloorPlanCanvas({
  plan,
  doc,
  editing,
  highlight,
  onAnnounce,
  onDropItem,
  onActivate,
}: FloorPlanCanvasProps) {
  const store = useEditorStore()
  const viewport = useEditor((s) => s.viewport)
  const selection = useEditor((s) => s.selection)
  const snap = useEditor((s) => s.snap)
  const showGrid = useEditor((s) => s.showGrid)
  const invalid = useEditor((s) => s.invalid)

  const svgRef = useRef<SVGSVGElement | null>(null)
  const gesture = useRef<Gesture | null>(null)
  const touches = useRef(new Map<number, { x: number; y: number }>())
  const pinch = useRef<{ distance: number; zoom: number } | null>(null)
  const frame = useRef<number | null>(null)
  const [preview, setPreview] = useState<Partial<Record<ObjectKey, Point | Fixture>>>({})
  /** The latest computed preview — the state above is applied once per animation frame. */
  const previewRef = useRef<Partial<Record<ObjectKey, Point | Fixture>>>({})
  const [ghost, setGhost] = useState<{ item: DragItem; point: Point; valid: boolean } | null>(null)
  const [rect, setRect] = useState({ width: 1, height: 1 })
  const patternId = useId().replace(/:/g, '')

  const roomWidth = doc.settings.width
  const roomHeight = doc.settings.height
  const room = { width: roomWidth, height: roomHeight }
  const grid = Math.max(1, doc.settings.grid_size)
  const size = plan.workstation_size
  const visible = visibleSize(room, viewport.zoom)
  const viewBox: ViewBox = {
    x: viewport.x,
    y: viewport.y,
    width: visible.width,
    height: visible.height,
  }
  const upp = unitsPerPixel(rect, viewBox)
  const pxPerUnit = 1 / upp

  const meta = useMemo(() => {
    const out = new Map<string, PcMeta>()
    for (const pc of plan.unplaced) {
      out.set(pc.id, {
        name: pc.name,
        unit_code: pc.unit_code,
        status: pc.status,
        openTicket: null,
      })
    }
    for (const pc of plan.pcs) {
      out.set(pc.id, {
        name: pc.name,
        unit_code: pc.unit_code,
        status: pc.status,
        openTicket: pc.open_ticket?.reference ?? null,
      })
    }
    return out
  }, [plan.pcs, plan.unplaced])

  // Confirmed equipment (DD-115), keyed by its `eq:` draft id.
  const equipment = useMemo(() => equipmentRoster(plan), [plan])
  const nameOf = (id: string): string | undefined => meta.get(id)?.name ?? equipment.get(id)?.name

  const selected = useMemo(() => new Set(selection), [selection])

  /* ── measurement ───────────────────────────────────────────────────── */

  useEffect(() => {
    const svg = svgRef.current
    if (!svg) return
    const measure = () => {
      const r = svg.getBoundingClientRect()
      setRect({ width: Math.max(1, r.width), height: Math.max(1, r.height) })
    }
    measure()
    if (typeof ResizeObserver === 'undefined') return
    const observer = new ResizeObserver(measure)
    observer.observe(svg)
    return () => observer.disconnect()
  }, [])

  const toLayout = useCallback(
    (clientX: number, clientY: number): Point => {
      const svg = svgRef.current
      if (!svg) return { x: 0, y: 0 }
      return clientToLayout(clientX, clientY, svg.getBoundingClientRect(), viewBox)
    },
    // viewBox is derived from the viewport; recompute when it moves.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [viewport.x, viewport.y, viewport.zoom, room.width, room.height],
  )

  /* ── geometry helpers ──────────────────────────────────────────────── */

  const extentOf = useCallback(
    (key: ObjectKey, source: LayoutDoc = doc): { x: number; y: number } => {
      if (key.startsWith('fx:')) {
        const f = source.fixtures[key.slice(3)]
        return f ? halfExtent(f.width, f.height, f.rotation) : { x: 0, y: 0 }
      }
      return { x: size / 2, y: size / 2 }
    },
    [doc, size],
  )

  const positionOf = useCallback(
    (key: ObjectKey): Point | null => {
      if (key.startsWith('pc:')) return doc.workstations[key.slice(3)] ?? null
      const f = doc.fixtures[key.slice(3)]
      return f ? { x: f.x, y: f.y } : null
    },
    [doc],
  )

  /* ── wheel: Ctrl/⌘ zooms about the pointer, otherwise pans ─────────── */

  useEffect(() => {
    const svg = svgRef.current
    if (!svg) return
    const onWheel = (event: WheelEvent) => {
      event.preventDefault()
      const r = svg.getBoundingClientRect()
      const focus = { u: (event.clientX - r.left) / r.width, v: (event.clientY - r.top) / r.height }
      const state = store.getState()
      if (event.ctrlKey || event.metaKey) {
        state.zoomTo(state.viewport.zoom * Math.exp(-event.deltaY * 0.0025), focus)
      } else {
        const scale = unitsPerPixel(r, {
          x: 0,
          y: 0,
          ...visibleSize({ width: roomWidth, height: roomHeight }, state.viewport.zoom),
        })
        state.panByUnits(event.deltaX * scale, event.deltaY * scale)
      }
    }
    svg.addEventListener('wheel', onWheel, { passive: false })
    return () => svg.removeEventListener('wheel', onWheel)
  }, [store, roomWidth, roomHeight])

  /* ── pointer gestures ──────────────────────────────────────────────── */

  const schedule = (work: () => void) => {
    if (frame.current !== null) cancelAnimationFrame(frame.current)
    frame.current = requestAnimationFrame(() => {
      frame.current = null
      work()
    })
  }

  const capture = (event: React.PointerEvent<Element>) => {
    try {
      svgRef.current?.setPointerCapture(event.pointerId)
    } catch {
      // A pointer that already ended cannot be captured; nothing to do.
    }
  }

  const onBackgroundPointerDown = (event: React.PointerEvent<SVGSVGElement>) => {
    if (event.button !== 0 && event.pointerType === 'mouse') return

    if (event.pointerType === 'touch') {
      touches.current.set(event.pointerId, { x: event.clientX, y: event.clientY })
      if (touches.current.size === 2) {
        const [a, b] = [...touches.current.values()]
        pinch.current = {
          distance: Math.hypot(a.x - b.x, a.y - b.y),
          zoom: store.getState().viewport.zoom,
        }
        gesture.current = null
        return
      }
    }

    capture(event)
    gesture.current = {
      kind: 'pan',
      pointerId: event.pointerId,
      last: { x: event.clientX, y: event.clientY },
      start: { x: event.clientX, y: event.clientY },
      moved: false,
    }
  }

  const beginObject = (key: ObjectKey) => (event: React.PointerEvent<SVGGElement>) => {
    if (event.button !== 0 && event.pointerType === 'mouse') return
    event.stopPropagation()

    const state = store.getState()

    if (!editing) {
      state.select([key])
      onActivate(key)
      return
    }

    if (event.shiftKey) {
      state.select([key], true)
      return
    }

    const current = state.selection.includes(key) ? state.selection : [key]
    if (!state.selection.includes(key)) state.select([key])

    const origins: Partial<Record<ObjectKey, Point>> = {}
    for (const k of current) {
      const p = positionOf(k)
      if (p) origins[k] = { x: p.x, y: p.y }
    }

    capture(event)
    gesture.current = {
      kind: 'move',
      pointerId: event.pointerId,
      start: toLayout(event.clientX, event.clientY),
      startClient: { x: event.clientX, y: event.clientY },
      origins,
      primary: key,
      moved: false,
    }
  }

  const beginHandle =
    (key: ObjectKey, kind: 'resize' | 'rotate', sx: 1 | -1 = 1, sy: 1 | -1 = 1) =>
    (event: React.PointerEvent<SVGElement>) => {
      event.stopPropagation()
      const fixture = doc.fixtures[key.slice(3)]
      if (!fixture) return
      capture(event)
      gesture.current =
        kind === 'resize'
          ? { kind, pointerId: event.pointerId, key, sx, sy, origin: { ...fixture } }
          : { kind, pointerId: event.pointerId, key, origin: { ...fixture } }
    }

  const onPointerMove = (event: React.PointerEvent<SVGSVGElement>) => {
    if (event.pointerType === 'touch' && touches.current.has(event.pointerId)) {
      touches.current.set(event.pointerId, { x: event.clientX, y: event.clientY })
      if (pinch.current && touches.current.size === 2) {
        const [a, b] = [...touches.current.values()]
        const distance = Math.hypot(a.x - b.x, a.y - b.y)
        const r = svgRef.current!.getBoundingClientRect()
        const focus = {
          u: ((a.x + b.x) / 2 - r.left) / r.width,
          v: ((a.y + b.y) / 2 - r.top) / r.height,
        }
        const target = pinch.current.zoom * (distance / Math.max(1, pinch.current.distance))
        schedule(() => store.getState().zoomTo(target, focus))
        return
      }
    }

    const g = gesture.current
    if (!g || g.pointerId !== event.pointerId) return

    if (g.kind === 'pan') {
      const dx = event.clientX - g.last.x
      const dy = event.clientY - g.last.y
      g.last = { x: event.clientX, y: event.clientY }
      if (Math.hypot(event.clientX - g.start.x, event.clientY - g.start.y) > DRAG_THRESHOLD_PX)
        g.moved = true
      store.getState().panByUnits(-dx * upp, -dy * upp)
      return
    }

    const point = toLayout(event.clientX, event.clientY)
    const free = event.altKey || !snap

    if (g.kind === 'move') {
      if (
        !g.moved &&
        Math.hypot(event.clientX - g.startClient.x, event.clientY - g.startClient.y) <=
          DRAG_THRESHOLD_PX
      ) {
        return
      }
      g.moved = true
      const primary = g.origins[g.primary]
      if (!primary) return
      const half = extentOf(g.primary)
      let tx = primary.x + (point.x - g.start.x)
      let ty = primary.y + (point.y - g.start.y)
      if (!free) {
        tx = snapValue(tx - half.x, grid) + half.x
        ty = snapValue(ty - half.y, grid) + half.y
      }
      let dx = tx - primary.x
      let dy = ty - primary.y

      // Keep the whole selection inside the room by limiting the shared delta.
      for (const [key, origin] of Object.entries(g.origins) as [ObjectKey, Point][]) {
        const h = extentOf(key)
        dx = Math.min(room.width - h.x - origin.x, Math.max(h.x - origin.x, dx))
        dy = Math.min(room.height - h.y - origin.y, Math.max(h.y - origin.y, dy))
      }

      const next: Partial<Record<ObjectKey, Point>> = {}
      for (const [key, origin] of Object.entries(g.origins) as [ObjectKey, Point][]) {
        next[key] = { x: round2(origin.x + dx), y: round2(origin.y + dy) }
      }
      previewRef.current = next
      schedule(() => setPreview(next))
      return
    }

    const f = g.origin
    const rad = (f.rotation * Math.PI) / 180

    if (g.kind === 'resize') {
      // Work in the fixture's own (unrotated) frame; the opposite corner stays put.
      const lx = (point.x - f.x) * Math.cos(-rad) - (point.y - f.y) * Math.sin(-rad)
      const ly = (point.x - f.x) * Math.sin(-rad) + (point.y - f.y) * Math.cos(-rad)
      const fixedX = (-g.sx * f.width) / 2
      const fixedY = (-g.sy * f.height) / 2
      let w = Math.max(MIN_FIXTURE, (lx - fixedX) * g.sx)
      let h = Math.max(MIN_FIXTURE, (ly - fixedY) * g.sy)
      if (!free) {
        w = Math.max(MIN_FIXTURE, snapValue(w, grid / 2))
        h = Math.max(MIN_FIXTURE, snapValue(h, grid / 2))
      }
      const cx = fixedX + (g.sx * w) / 2
      const cy = fixedY + (g.sy * h) / 2
      const next: Fixture = {
        ...f,
        width: round2(w),
        height: round2(h),
        x: round2(f.x + cx * Math.cos(rad) - cy * Math.sin(rad)),
        y: round2(f.y + cx * Math.sin(rad) + cy * Math.cos(rad)),
      }
      if (pointsInsideRoom(corners(next.x, next.y, next.width, next.height, next.rotation), room)) {
        previewRef.current = { [g.key]: next }
        schedule(() => setPreview({ [g.key]: next }))
      }
      return
    }

    // rotate
    let angle = (Math.atan2(point.y - f.y, point.x - f.x) * 180) / Math.PI + 90
    if (!free) angle = Math.round(angle / 15) * 15
    const next: Fixture = { ...f, rotation: normalizeRotation(angle) }
    if (pointsInsideRoom(corners(next.x, next.y, next.width, next.height, next.rotation), room)) {
      previewRef.current = { [g.key]: next }
      schedule(() => setPreview({ [g.key]: next }))
    }
  }

  const finish = (event: React.PointerEvent<SVGSVGElement>, cancelled: boolean) => {
    if (event.pointerType === 'touch') {
      touches.current.delete(event.pointerId)
      if (touches.current.size < 2) pinch.current = null
    }

    const g = gesture.current
    if (!g || g.pointerId !== event.pointerId) return
    gesture.current = null
    if (frame.current !== null) {
      cancelAnimationFrame(frame.current)
      frame.current = null
    }

    const state = store.getState()

    if (g.kind === 'pan') {
      if (!g.moved && !cancelled && editing) state.select([])
      return
    }

    const latest = { ...previewRef.current }
    previewRef.current = {}
    setPreview({})
    if (cancelled) return

    if (g.kind === 'move') {
      if (!g.moved) {
        onActivate(g.primary)
        return
      }
      // The ref, not the state: a release in the same frame as the last move
      // still lands exactly where the preview was computed.
      const moved = Object.keys(latest).length > 0 ? (latest as Record<ObjectKey, Point>) : null
      if (!moved) return

      const statics: Record<string, Point> = {}
      for (const [id, w] of Object.entries(doc.workstations)) {
        if (!moved[`pc:${id}`]) statics[id] = w
      }
      for (const [key, point] of Object.entries(moved) as [ObjectKey, Point][]) {
        if (!key.startsWith('pc:')) continue
        const hit = overlapsAny(key.slice(3), point, statics, size)
        if (hit) {
          onAnnounce(
            `Not moved: ${nameOf(key.slice(3)) ?? 'that workstation'} would overlap ${nameOf(hit) ?? 'another workstation'}.`,
          )
          return
        }
      }

      state.edit((d) => applyPoints(d, moved))
      onAnnounce(
        Object.keys(moved).length === 1
          ? `${describe(g.primary, doc, nameOf)} moved to x ${Math.round(moved[g.primary]?.x ?? 0)}, y ${Math.round(moved[g.primary]?.y ?? 0)}.`
          : `${Object.keys(moved).length} objects moved.`,
      )
      return
    }

    const next = latest[g.key] as Fixture | undefined
    if (!next) return
    state.edit((d) => ({ ...d, fixtures: { ...d.fixtures, [next.id]: next } }))
    onAnnounce(
      g.kind === 'resize'
        ? `${FIXTURES[next.kind].label} resized to ${Math.round(next.width)} by ${Math.round(next.height)}.`
        : `${FIXTURES[next.kind].label} turned to ${Math.round(next.rotation)}°.`,
    )
  }

  /* ── drag and drop from the side panels ────────────────────────────── */

  const dropPoint = (item: DragItem, clientX: number, clientY: number) => {
    const raw = toLayout(clientX, clientY)
    if (item.type === 'pc') {
      const point = snapAndClamp(raw, { x: size / 2, y: size / 2 }, room, grid, snap)
      const valid = !overlapsAny(item.id, point, doc.workstations, size)
      return { point, valid }
    }
    const preset = FIXTURES[item.kind]
    const point = snapAndClamp(raw, halfExtent(preset.width, preset.height, 0), room, grid, snap)
    return { point, valid: true }
  }

  const readItem = (event: React.DragEvent): DragItem | null => {
    try {
      const raw = event.dataTransfer.getData(DRAG_MIME)
      return raw ? (JSON.parse(raw) as DragItem) : null
    } catch {
      return null
    }
  }

  const draggedItem = useRef<DragItem | null>(null)

  useEffect(() => {
    const onStart = (event: Event) => {
      draggedItem.current = (event as CustomEvent<DragItem>).detail
    }
    const onEnd = () => {
      draggedItem.current = null
      setGhost(null)
    }
    window.addEventListener('floorplan:dragstart', onStart)
    window.addEventListener('dragend', onEnd)
    return () => {
      window.removeEventListener('floorplan:dragstart', onStart)
      window.removeEventListener('dragend', onEnd)
    }
  }, [])

  /* ── render ────────────────────────────────────────────────────────── */

  const geometryOf = (key: ObjectKey): Point | Fixture | undefined => preview[key]

  const workstations = Object.entries(doc.workstations).map(([id, w]) => {
    const p = (geometryOf(`pc:${id}`) as Point | undefined) ?? w
    return { id, geom: { ...w, x: p.x, y: p.y } as WorkstationGeom }
  })
  const fixtures = inDrawOrder(
    Object.values(doc.fixtures).map((f) => {
      const p = geometryOf(`fx:${f.id}`)
      if (!p) return f
      return 'width' in p ? (p as Fixture) : { ...f, x: p.x, y: p.y }
    }),
  )
  const singleFixture =
    editing && selection.length === 1 && selection[0].startsWith('fx:')
      ? fixtures.find((f) => `fx:${f.id}` === selection[0])
      : undefined

  const showMinor = grid * pxPerUnit >= 6
  const showLabels = size * pxPerUnit >= 28
  const handle = 10 * upp

  return (
    <svg
      ref={svgRef}
      data-testid="floor-plan-svg"
      viewBox={`${round2(viewBox.x)} ${round2(viewBox.y)} ${round2(viewBox.width)} ${round2(viewBox.height)}`}
      preserveAspectRatio="xMidYMid meet"
      className={cn(
        'block size-full touch-none select-none',
        editing ? 'cursor-default' : 'cursor-grab',
      )}
      onPointerDown={onBackgroundPointerDown}
      onPointerMove={onPointerMove}
      onPointerUp={(e) => finish(e, false)}
      onPointerCancel={(e) => finish(e, true)}
      onLostPointerCapture={(e) => {
        if (gesture.current?.pointerId === e.pointerId) finish(e, true)
      }}
      onDragOver={(event) => {
        if (!editing || !event.dataTransfer.types.includes(DRAG_MIME)) return
        event.preventDefault()
        event.dataTransfer.dropEffect = 'copy'
        const item = draggedItem.current
        if (item) setGhost({ item, ...dropPoint(item, event.clientX, event.clientY) })
      }}
      onDragLeave={() => setGhost(null)}
      onDrop={(event) => {
        if (!editing) return
        event.preventDefault()
        const item = readItem(event) ?? draggedItem.current
        setGhost(null)
        if (!item) return
        const { point, valid } = dropPoint(item, event.clientX, event.clientY)
        if (!valid) {
          onAnnounce('Not placed: that spot overlaps another workstation.')
          return
        }
        onDropItem(item, point)
      }}
    >
      <defs>
        <pattern id={`${patternId}-minor`} width={grid} height={grid} patternUnits="userSpaceOnUse">
          <path
            d={`M ${grid} 0 L 0 0 0 ${grid}`}
            className="fill-none stroke-(--grid-line)"
            strokeWidth={1}
            vectorEffect="non-scaling-stroke"
          />
        </pattern>
        <pattern
          id={`${patternId}-major`}
          width={grid * 5}
          height={grid * 5}
          patternUnits="userSpaceOnUse"
        >
          <path
            d={`M ${grid * 5} 0 L 0 0 0 ${grid * 5}`}
            className="fill-none stroke-border"
            strokeWidth={1}
            vectorEffect="non-scaling-stroke"
          />
        </pattern>
      </defs>

      {/* The room. */}
      <rect
        data-testid="floor-plan-room"
        x={0}
        y={0}
        width={room.width}
        height={room.height}
        className="fill-surface"
      />
      {showGrid && (
        <g data-testid="floor-plan-grid" aria-hidden="true" className="pointer-events-none">
          {showMinor && (
            <rect
              x={0}
              y={0}
              width={room.width}
              height={room.height}
              fill={`url(#${patternId}-minor)`}
            />
          )}
          <rect
            x={0}
            y={0}
            width={room.width}
            height={room.height}
            fill={`url(#${patternId}-major)`}
          />
        </g>
      )}
      <rect
        x={0}
        y={0}
        width={room.width}
        height={room.height}
        className="pointer-events-none fill-none stroke-ink"
        strokeWidth={2}
        vectorEffect="non-scaling-stroke"
      />

      {fixtures.map((f) => (
        <FixtureShape
          key={f.id}
          fixture={f}
          selected={selected.has(`fx:${f.id}`)}
          invalid={Boolean(invalid[`fx:${f.id}`])}
          interactive={editing}
          showLabel={pxPerUnit * Math.min(f.width, f.height) >= 14}
          onPointerDown={editing ? beginObject(`fx:${f.id}`) : undefined}
          onKeyDown={(event) => {
            if (event.key === 'Enter' || event.key === ' ') {
              event.preventDefault()
              store.getState().select([`fx:${f.id}`])
              onActivate(`fx:${f.id}`)
            }
          }}
        />
      ))}

      {workstations.map(({ id, geom }) => {
        const key: ObjectKey = `pc:${id}`
        if (isEquipmentId(id)) {
          const item = equipment.get(id)
          if (!item) return null
          return (
            <EquipmentNode
              key={id}
              id={id}
              name={item.name}
              category={item.category}
              status={item.status}
              x={geom.x}
              y={geom.y}
              rotation={geom.rotation}
              size={size}
              selected={selected.has(key)}
              invalid={Boolean(invalid[key])}
              // The status legend highlights PC repair statuses; equipment has none.
              dimmed={highlight !== null}
              interactive={editing}
              showLabel={showLabels}
              onPointerDown={beginObject(key)}
              onKeyDown={(event) => {
                if (event.key === 'Enter' || event.key === ' ') {
                  event.preventDefault()
                  store.getState().select([key])
                  onActivate(key)
                }
              }}
            />
          )
        }
        const info = meta.get(id)
        if (!info) return null
        return (
          <PcNode
            key={id}
            id={id}
            name={info.name}
            status={info.status}
            x={geom.x}
            y={geom.y}
            rotation={geom.rotation}
            size={size}
            openTicket={info.openTicket}
            selected={selected.has(key)}
            invalid={Boolean(invalid[key])}
            dimmed={highlight !== null && !highlight.has(info.status.value)}
            interactive={editing}
            showLabel={showLabels}
            onPointerDown={beginObject(key)}
            onKeyDown={(event) => {
              if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault()
                store.getState().select([key])
                onActivate(key)
              }
            }}
          />
        )
      })}

      {singleFixture && (
        <g
          data-testid="floor-plan-handles"
          transform={`translate(${singleFixture.x} ${singleFixture.y}) rotate(${singleFixture.rotation})`}
        >
          <line
            x1={0}
            y1={-singleFixture.height / 2}
            x2={0}
            y2={-singleFixture.height / 2 - 26 * upp}
            className="stroke-primary"
            strokeWidth={1.5}
            vectorEffect="non-scaling-stroke"
          />
          <circle
            data-testid="floor-plan-rotate-handle"
            cx={0}
            cy={-singleFixture.height / 2 - 26 * upp}
            r={handle * 0.7}
            className="cursor-grab fill-surface stroke-primary"
            strokeWidth={2}
            vectorEffect="non-scaling-stroke"
            style={{ touchAction: 'none' }}
            onPointerDown={beginHandle(`fx:${singleFixture.id}`, 'rotate')}
          >
            <title>Turn</title>
          </circle>
          {(
            [
              [-1, -1],
              [1, -1],
              [1, 1],
              [-1, 1],
            ] as [1 | -1, 1 | -1][]
          ).map(([sx, sy]) => (
            <rect
              key={`${sx}${sy}`}
              data-testid="floor-plan-resize-handle"
              x={(sx * singleFixture.width) / 2 - handle / 2}
              y={(sy * singleFixture.height) / 2 - handle / 2}
              width={handle}
              height={handle}
              className={cn(
                'fill-surface stroke-primary',
                sx === sy ? 'cursor-nwse-resize' : 'cursor-nesw-resize',
              )}
              strokeWidth={2}
              vectorEffect="non-scaling-stroke"
              style={{ touchAction: 'none' }}
              onPointerDown={beginHandle(`fx:${singleFixture.id}`, 'resize', sx, sy)}
            >
              <title>Resize</title>
            </rect>
          ))}
        </g>
      )}

      {ghost && (
        <g data-testid="floor-plan-ghost" className="pointer-events-none" opacity={0.7}>
          {ghost.item.type === 'pc' ? (
            <rect
              x={ghost.point.x - size / 2}
              y={ghost.point.y - size / 2}
              width={size}
              height={size}
              rx={6}
              className={
                ghost.valid
                  ? 'fill-primary-subtle stroke-primary'
                  : 'fill-danger-subtle stroke-danger'
              }
              strokeWidth={2}
              strokeDasharray="5 3"
              vectorEffect="non-scaling-stroke"
            />
          ) : (
            <rect
              x={ghost.point.x - FIXTURES[ghost.item.kind].width / 2}
              y={ghost.point.y - FIXTURES[ghost.item.kind].height / 2}
              width={FIXTURES[ghost.item.kind].width}
              height={FIXTURES[ghost.item.kind].height}
              className="fill-primary-subtle stroke-primary"
              strokeWidth={2}
              strokeDasharray="5 3"
              vectorEffect="non-scaling-stroke"
            />
          )}
        </g>
      )}
    </svg>
  )
}

function applyPoints(doc: LayoutDoc, points: Partial<Record<ObjectKey, Point>>): LayoutDoc {
  const workstations = { ...doc.workstations }
  const fixtures = { ...doc.fixtures }
  for (const [key, p] of Object.entries(points) as [ObjectKey, Point][]) {
    const id = key.slice(3)
    if (key.startsWith('pc:') && workstations[id])
      workstations[id] = { ...workstations[id], x: p.x, y: p.y }
    if (key.startsWith('fx:') && fixtures[id]) fixtures[id] = { ...fixtures[id], x: p.x, y: p.y }
  }
  return { ...doc, workstations, fixtures }
}

function describe(
  key: ObjectKey,
  doc: LayoutDoc,
  nameOf: (id: string) => string | undefined,
): string {
  const id = key.slice(3)
  if (key.startsWith('pc:')) return nameOf(id) ?? 'Workstation'
  const f = doc.fixtures[id]
  return f ? FIXTURES[f.kind].label : 'Fixture'
}

/**
 * Viewport arithmetic for the floor-plan map — pure functions, no React.
 *
 * The map is an SVG whose `viewBox` is a window onto the layout, in layout
 * units (the same pixels the server stores coordinates in). The window is
 * described by a zoom factor and the layout point at its top-left corner:
 *
 *     visible width  = layout width  / zoom
 *     visible height = layout height / zoom
 *
 * so zooming and panning change the `viewBox` and nothing else — there is no
 * CSS transform to keep in step with pointer maths.
 */

export const MIN_ZOOM = 0.5
export const MAX_ZOOM = 4
/** One button press or key press. */
export const ZOOM_STEP = 1.25
/** A keyboard or button pan moves the window by this share of what is visible. */
export const PAN_STEP = 0.15

export interface Size {
  width: number
  height: number
}

export interface Viewport {
  zoom: number
  /** Layout x of the window's left edge. */
  x: number
  /** Layout y of the window's top edge. */
  y: number
}

/** Where, as a 0–1 fraction of the visible window, a zoom should hold still. */
export interface Focus {
  u: number
  v: number
}

const CENTER: Focus = { u: 0.5, v: 0.5 }

export const INITIAL_VIEWPORT: Viewport = { zoom: 1, x: 0, y: 0 }

export function clampZoom(zoom: number): number {
  if (!Number.isFinite(zoom)) return 1
  return Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, zoom))
}

export function visibleSize(content: Size, zoom: number): Size {
  return { width: content.width / zoom, height: content.height / zoom }
}

/**
 * Keep the middle of the window over the layout, so the plan can never be
 * dragged wholly out of view and left as an empty grey pane.
 */
export function clampViewport(viewport: Viewport, content: Size): Viewport {
  const zoom = clampZoom(viewport.zoom)
  const visible = visibleSize(content, zoom)

  const clamp = (value: number, extent: number, shown: number) =>
    Math.min(extent - shown / 2, Math.max(-shown / 2, value))

  return {
    zoom,
    x: clamp(viewport.x, content.width, visible.width),
    y: clamp(viewport.y, content.height, visible.height),
  }
}

/** Change zoom while the point under `focus` stays where it is on screen. */
export function zoomAt(
  viewport: Viewport,
  content: Size,
  nextZoom: number,
  focus: Focus = CENTER,
): Viewport {
  const zoom = clampZoom(nextZoom)
  const before = visibleSize(content, viewport.zoom)
  const after = visibleSize(content, zoom)

  const fx = viewport.x + focus.u * before.width
  const fy = viewport.y + focus.v * before.height

  return clampViewport(
    { zoom, x: fx - focus.u * after.width, y: fy - focus.v * after.height },
    content,
  )
}

/** Move the window by `dx`, `dy` layout units. */
export function panBy(viewport: Viewport, content: Size, dx: number, dy: number): Viewport {
  return clampViewport({ ...viewport, x: viewport.x + dx, y: viewport.y + dy }, content)
}

/** The `viewBox` attribute for a viewport. */
export function toViewBox(viewport: Viewport, content: Size): string {
  const visible = visibleSize(content, viewport.zoom)
  return `${round(viewport.x)} ${round(viewport.y)} ${round(visible.width)} ${round(visible.height)}`
}

function round(value: number): number {
  return Math.round(value * 100) / 100
}

import { create } from 'zustand'
import {
  INITIAL_VIEWPORT,
  PAN_STEP,
  ZOOM_STEP,
  clampViewport,
  panBy,
  visibleSize,
  zoomAt,
  type Focus,
  type Size,
  type Viewport,
} from '../lib/viewport'

/**
 * Where the floor-plan map is looking. **View state only.**
 *
 * Deliberately holds no room, layout, PC or permission data: those are server
 * state (TanStack Query) and authorization (the backend, mirrored by route
 * guards). Keeping the store to the viewport means a stale copy of anything
 * that matters can never live here.
 *
 * `content` is the size of the layout being viewed. It is what the pan limits
 * are measured against, and changing it (a different room, a new layout
 * version) resets the view, so one room's zoom is never carried into another.
 */
interface ViewportState extends Viewport {
  content: Size | null
  setContent: (size: Size) => void
  zoomIn: (focus?: Focus) => void
  zoomOut: (focus?: Focus) => void
  zoomTo: (zoom: number, focus?: Focus) => void
  /** Pan by a share of a step: (1, 0) is one step right, a step being a share of the visible window. */
  panByStep: (fx: number, fy: number) => void
  /** Pan by layout units — what a pointer drag produces. */
  panByUnits: (dx: number, dy: number) => void
  /** Back to the whole layout at 100%. */
  reset: () => void
}

export const useViewportStore = create<ViewportState>((set, get) => {
  /** Apply a viewport change only once a layout has told us its size. */
  const update = (next: (content: Size, current: Viewport) => Viewport) => {
    const { content, zoom, x, y } = get()
    if (!content) return
    set(next(content, { zoom, x, y }))
  }

  return {
    ...INITIAL_VIEWPORT,
    content: null,

    setContent: (size) => {
      const { content } = get()
      if (content && content.width === size.width && content.height === size.height) return
      set({ content: size, ...INITIAL_VIEWPORT })
    },

    zoomIn: (focus) => update((content, vp) => zoomAt(vp, content, vp.zoom * ZOOM_STEP, focus)),
    zoomOut: (focus) => update((content, vp) => zoomAt(vp, content, vp.zoom / ZOOM_STEP, focus)),
    zoomTo: (zoom, focus) => update((content, vp) => zoomAt(vp, content, zoom, focus)),

    panByStep: (fx, fy) =>
      update((content, vp) => {
        const visible = visibleSize(content, vp.zoom)
        return panBy(vp, content, fx * visible.width * PAN_STEP, fy * visible.height * PAN_STEP)
      }),

    panByUnits: (dx, dy) => update((content, vp) => panBy(vp, content, dx, dy)),

    reset: () => update((content) => clampViewport(INITIAL_VIEWPORT, content)),
  }
})

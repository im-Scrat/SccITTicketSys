import type { FixtureKind } from '../types'

/** What can be dragged onto the canvas from the side panels (HTML drag and drop — mouse and pen). */
export type DragItem = { type: 'pc'; id: string } | { type: 'fixture'; kind: FixtureKind }

export const DRAG_MIME = 'application/x-sccit-floorplan'

/**
 * Start dragging `item` towards the canvas. `dataTransfer` cannot be read
 * during `dragover`, so the canvas is also told through a window event, which
 * is what lets it draw a snapped preview before the drop.
 */
export function startDrag(event: React.DragEvent, item: DragItem) {
  event.dataTransfer.setData(DRAG_MIME, JSON.stringify(item))
  event.dataTransfer.effectAllowed = 'copy'
  window.dispatchEvent(new CustomEvent('floorplan:dragstart', { detail: item }))
}

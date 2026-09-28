import { ArrowDown, ArrowLeft, ArrowRight, ArrowUp, Maximize, ZoomIn, ZoomOut } from 'lucide-react'
import { Button } from '@/components/ui/Button'
import { MAX_ZOOM, MIN_ZOOM } from '../lib/viewport'
import { useViewportStore } from '../stores/useViewportStore'

/**
 * View controls for the map: zoom, pan and reset.
 *
 * Every control is a real button with a visible text label (icons are never
 * alone in this app) and is reachable by keyboard, so the map is fully usable
 * without a pointer. The pan buttons are the pointer-free alternative to
 * dragging; the same keys work on the map itself.
 *
 * A `group`, not an ARIA `toolbar`: a toolbar promises arrow-key roving between
 * its controls, and here the arrow keys belong to panning the map.
 */
export function FloorPlanToolbar() {
  const zoom = useViewportStore((s) => s.zoom)
  const zoomIn = useViewportStore((s) => s.zoomIn)
  const zoomOut = useViewportStore((s) => s.zoomOut)
  const reset = useViewportStore((s) => s.reset)
  const panByStep = useViewportStore((s) => s.panByStep)

  return (
    <div
      role="group"
      aria-label="Floor plan view controls"
      className="flex flex-wrap items-center gap-x-6 gap-y-3"
    >
      <div role="group" aria-label="Zoom" className="flex items-center gap-2">
        <Button
          variant="secondary"
          size="sm"
          leftIcon={<ZoomOut size={18} aria-hidden="true" />}
          onClick={() => zoomOut()}
          disabled={zoom <= MIN_ZOOM}
          aria-label="Zoom out"
        >
          Zoom out
        </Button>
        <Button
          variant="secondary"
          size="sm"
          leftIcon={<ZoomIn size={18} aria-hidden="true" />}
          onClick={() => zoomIn()}
          disabled={zoom >= MAX_ZOOM}
          aria-label="Zoom in"
        >
          Zoom in
        </Button>
        <span role="status" className="tnum min-w-14 text-sm font-medium text-ink">
          <span className="sr-only">Zoom level </span>
          {Math.round(zoom * 100)}%
        </span>
      </div>

      <div role="group" aria-label="Pan" className="flex items-center gap-2">
        <Button
          variant="ghost"
          size="sm"
          leftIcon={<ArrowLeft size={18} aria-hidden="true" />}
          onClick={() => panByStep(-1, 0)}
          aria-label="Pan left"
        >
          Left
        </Button>
        <Button
          variant="ghost"
          size="sm"
          leftIcon={<ArrowUp size={18} aria-hidden="true" />}
          onClick={() => panByStep(0, -1)}
          aria-label="Pan up"
        >
          Up
        </Button>
        <Button
          variant="ghost"
          size="sm"
          leftIcon={<ArrowDown size={18} aria-hidden="true" />}
          onClick={() => panByStep(0, 1)}
          aria-label="Pan down"
        >
          Down
        </Button>
        <Button
          variant="ghost"
          size="sm"
          leftIcon={<ArrowRight size={18} aria-hidden="true" />}
          onClick={() => panByStep(1, 0)}
          aria-label="Pan right"
        >
          Right
        </Button>
      </div>

      <Button
        variant="secondary"
        size="sm"
        leftIcon={<Maximize size={18} aria-hidden="true" />}
        onClick={() => reset()}
        aria-label="Reset view"
      >
        Reset view
      </Button>
    </div>
  )
}

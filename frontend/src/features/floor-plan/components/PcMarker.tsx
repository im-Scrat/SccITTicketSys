import { cn } from '@/lib/cn'
import { STATUS_SHAPES, TONE_SVG, polygonPoints } from '../lib/presentation'
import type { PcStatusValue, Tone } from '../types'

/**
 * The outline that stands for one status: a circle, square, triangle, diamond,
 * hexagon or dashed octagon, filled and stroked in the server's tone.
 *
 * Drawn around the origin so callers position it with a `translate`. Shared by
 * the map's nodes and the legend's swatches, so what the legend shows is
 * exactly what the map draws.
 */
export function PcMarker({
  status,
  tone,
  radius,
  className,
}: {
  status: PcStatusValue
  tone: Tone
  radius: number
  className?: string
}) {
  const shape = STATUS_SHAPES[status]
  const common = {
    className: cn(TONE_SVG[tone], className),
    strokeWidth: 2,
    strokeDasharray: shape.dashed ? '4 3' : undefined,
    // Keep the outline a constant weight however far the map is zoomed.
    vectorEffect: 'non-scaling-stroke' as const,
  }

  if (shape.sides === 0) return <circle r={radius} {...common} />

  return <polygon points={polygonPoints(shape.sides, radius, shape.startDeg)} {...common} />
}

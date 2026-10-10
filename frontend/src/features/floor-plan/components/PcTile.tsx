import { Monitor } from 'lucide-react'
import { cn } from '@/lib/cn'
import { TONE_SVG, TONE_TEXT } from '../lib/presentation'
import type { PcStatusValue, Tone } from '../types'
import { PcMarker } from './PcMarker'

/**
 * One machine on the map: a rounded tile in the status hue with a monitor
 * icon, and the status *shape* as a small glyph on the tile's corner.
 *
 * Drawn around the origin so callers position it with a `translate`. Shared by
 * the map's nodes and the legend's swatches, so what the legend shows is
 * exactly what the map draws. The glyph is `PcMarker` unchanged — status is
 * still never colour alone (FR-FP-004): shape on the tile, label under it.
 */
export function PcTile({
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
  const size = radius * 2

  return (
    <>
      <rect
        x={-radius}
        y={-radius}
        width={size}
        height={size}
        rx={radius * 0.3}
        className={cn(TONE_SVG[tone], className)}
        strokeWidth={2}
        vectorEffect="non-scaling-stroke"
        data-testid="floor-plan-tile"
      />
      <Monitor
        x={-radius * 0.52}
        y={-radius * 0.56}
        width={radius * 1.04}
        height={radius * 1.04}
        strokeWidth={2}
        className={TONE_TEXT[tone]}
        aria-hidden="true"
        focusable="false"
      />
      {/* The status glyph, bottom-right, with the tile's own fill behind it so it reads on any hue. */}
      <g transform={`translate(${radius * 0.66} ${radius * 0.66})`}>
        <PcMarker status={status} tone={tone} radius={radius * 0.3} />
      </g>
    </>
  )
}

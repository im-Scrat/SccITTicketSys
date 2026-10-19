import { memo } from 'react'
import { FIXTURES } from '../lib/fixtures'
import type { FloorPlanRoomSummary } from '../types'

const DOT: Record<string, string> = {
  available: 'fill-success stroke-success-strong',
  pending_repair: 'fill-warning stroke-warning-strong',
  needs_fix: 'fill-danger stroke-danger-strong',
}

/**
 * A room's Floorplan in miniature for its overview card (Phase 2.11) — the
 * server's integer-rounded preview, drawn at the room's own proportions.
 * Decorative: the card's text says everything the picture shows.
 */
export const RoomThumbnail = memo(function RoomThumbnail({
  layout,
  preview,
}: {
  layout: NonNullable<FloorPlanRoomSummary['layout']>
  preview: NonNullable<FloorPlanRoomSummary['preview']>
}) {
  const size = 40

  return (
    <svg
      aria-hidden="true"
      viewBox={`-10 -10 ${layout.width + 20} ${layout.height + 20}`}
      preserveAspectRatio="xMidYMid meet"
      className="block size-full"
    >
      <rect
        x={0}
        y={0}
        width={layout.width}
        height={layout.height}
        rx={6}
        className="fill-surface stroke-border"
        strokeWidth={2}
        vectorEffect="non-scaling-stroke"
      />
      {[...preview.fixtures]
        .sort((a, b) => FIXTURES[a[0]].layer - FIXTURES[b[0]].layer)
        .map(([kind, x, y, w, h, r], i) => (
          <rect
            key={`f${i}`}
            transform={`translate(${x} ${y}) rotate(${r})`}
            x={-w / 2}
            y={-h / 2}
            width={w}
            height={h}
            className={
              kind === 'zone'
                ? 'fill-surface-sunken stroke-none'
                : kind === 'wall' || kind === 'partition'
                  ? 'fill-muted stroke-none'
                  : 'fill-surface-sunken stroke-faint'
            }
            strokeWidth={1}
            vectorEffect="non-scaling-stroke"
          />
        ))}
      {preview.pcs.map(([x, y, r, status], i) => (
        <rect
          key={`p${i}`}
          transform={`translate(${x} ${y}) rotate(${r})`}
          x={-size / 2}
          y={-size / 2}
          width={size}
          height={size}
          rx={6}
          className={DOT[status] ?? DOT.available}
          strokeWidth={1}
          vectorEffect="non-scaling-stroke"
        />
      ))}
    </svg>
  )
})

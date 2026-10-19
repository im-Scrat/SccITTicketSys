import { memo } from 'react'
import { Ticket } from 'lucide-react'
import { cn } from '@/lib/cn'
import { PC_STATUS } from '@/lib/pcStatus'
import type { PcStatus } from '../types'

/** SVG fill/stroke per status tone — the same tokens `PcStatusBadge` uses. */
const TONE = {
  success: { tile: 'fill-success-subtle stroke-success-strong', ink: 'text-success-strong' },
  warning: { tile: 'fill-warning-subtle stroke-warning-strong', ink: 'text-warning-strong' },
  danger: { tile: 'fill-danger-subtle stroke-danger-strong', ink: 'text-danger-strong' },
} as const

interface PcNodeProps {
  id: string
  name: string
  status: PcStatus
  x: number
  y: number
  rotation: number
  size: number
  openTicket: string | null
  selected: boolean
  invalid: boolean
  dimmed: boolean
  interactive: boolean
  /** Whether the label fits on screen at the current zoom. */
  showLabel: boolean
  onPointerDown?: (event: React.PointerEvent<SVGGElement>) => void
  onKeyDown?: (event: React.KeyboardEvent<SVGGElement>) => void
  onFocus?: () => void
}

/**
 * One workstation on the Floorplan (Phase 2.11).
 *
 * Status is said three ways, never by colour alone (FR-FP-004): the tile's
 * wash and border in the status hue, the status's own icon inside the tile,
 * and the status in words in the accessible name. A short bar on one edge is
 * the screen side — it turns with the workstation, so its facing reads at a
 * glance. The name sits upright beneath the tile whatever the rotation.
 */
export const PcNode = memo(function PcNode({
  id,
  name,
  status,
  x,
  y,
  rotation,
  size,
  openTicket,
  selected,
  invalid,
  dimmed,
  interactive,
  showLabel,
  onPointerDown,
  onKeyDown,
  onFocus,
}: PcNodeProps) {
  const tone = TONE[status.tone] ?? TONE.success
  const Icon = PC_STATUS[status.value]?.icon
  const half = size / 2
  const icon = size * 0.5
  const label = `${name}, ${status.label}, at x ${Math.round(x)}, y ${Math.round(y)}${
    rotation ? `, turned ${rotation}°` : ''
  }${openTicket ? `, open ticket ${openTicket}` : ''}`

  return (
    <g
      data-testid="floor-plan-node"
      data-pc-id={id}
      transform={`translate(${x} ${y})`}
      role={interactive ? 'button' : 'img'}
      tabIndex={0}
      aria-label={label}
      aria-pressed={interactive ? selected : undefined}
      aria-invalid={invalid || undefined}
      className={cn(
        'outline-none [&:focus-visible>.focus-ring]:opacity-100',
        dimmed && 'opacity-35',
        interactive ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
      )}
      style={{ touchAction: 'none' }}
      onPointerDown={onPointerDown}
      onKeyDown={onKeyDown}
      onFocus={onFocus}
    >
      <title>{label}</title>
      <rect
        className="focus-ring fill-none stroke-focus opacity-0"
        x={-half - 6}
        y={-half - 6}
        width={size + 12}
        height={size + 12}
        rx={8}
        strokeWidth={2.5}
        vectorEffect="non-scaling-stroke"
      />
      <g transform={rotation ? `rotate(${rotation})` : undefined}>
        <rect
          data-testid="floor-plan-tile"
          className={cn(tone.tile, invalid && 'stroke-danger')}
          x={-half}
          y={-half}
          width={size}
          height={size}
          rx={6}
          strokeWidth={invalid ? 3 : 2}
          vectorEffect="non-scaling-stroke"
        />
        {/* The screen side. */}
        <rect
          className={cn('fill-current', tone.ink)}
          x={-size * 0.3}
          y={-half + size * 0.08}
          width={size * 0.6}
          height={size * 0.1}
          rx={1.5}
        />
      </g>
      {Icon && (
        <Icon
          aria-hidden="true"
          className={tone.ink}
          x={-icon / 2}
          y={-icon / 2 + size * 0.06}
          width={icon}
          height={icon}
          strokeWidth={2.4}
        />
      )}
      {openTicket && (
        <g transform={`translate(${half - 2} ${-half + 2})`} aria-hidden="true">
          <circle
            r={size * 0.2}
            className="fill-surface stroke-ink"
            strokeWidth={1.5}
            vectorEffect="non-scaling-stroke"
          />
          <Ticket
            className="text-ink"
            x={-size * 0.12}
            y={-size * 0.12}
            width={size * 0.24}
            height={size * 0.24}
            strokeWidth={2.6}
          />
        </g>
      )}
      {selected && (
        <rect
          data-testid="floor-plan-selection"
          className="pointer-events-none fill-none stroke-primary"
          x={-half - 4}
          y={-half - 4}
          width={size + 8}
          height={size + 8}
          rx={8}
          strokeWidth={2}
          strokeDasharray="5 3"
          vectorEffect="non-scaling-stroke"
        />
      )}
      {showLabel && (
        <text
          aria-hidden="true"
          className="pointer-events-none fill-ink-strong font-medium select-none"
          y={half + size * 0.34}
          textAnchor="middle"
          fontSize={size * 0.3}
          paintOrder="stroke"
          stroke="var(--color-surface)"
          strokeWidth={size * 0.08}
        >
          {name}
        </text>
      )}
    </g>
  )
})

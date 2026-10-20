import { memo } from 'react'
import { Package } from 'lucide-react'
import { cn } from '@/lib/cn'
import type { EquipmentStatus } from '../types'

/** The asset status tones, on the same tokens as `AssetStatusBadge`. */
const TONE = {
  neutral: { tile: 'fill-surface-sunken stroke-control-border', ink: 'text-muted' },
  success: { tile: 'fill-success-subtle stroke-success-strong', ink: 'text-success-strong' },
  warning: { tile: 'fill-warning-subtle stroke-warning-strong', ink: 'text-warning-strong' },
  danger: { tile: 'fill-danger-subtle stroke-danger-strong', ink: 'text-danger-strong' },
} as const

interface EquipmentNodeProps {
  id: string
  name: string
  category: string | null
  status: EquipmentStatus
  x: number
  y: number
  rotation: number
  size: number
  selected: boolean
  invalid: boolean
  dimmed: boolean
  interactive: boolean
  showLabel: boolean
  onPointerDown?: (event: React.PointerEvent<SVGGElement>) => void
  onKeyDown?: (event: React.KeyboardEvent<SVGGElement>) => void
}

/**
 * Confirmed equipment on the Floorplan (owner request 2026-10-20, SDD DD-115).
 *
 * Same footprint, focus ring, selection and keyboard behaviour as a
 * workstation ({@see PcNode}), so moving it feels the same — but drawn so it is
 * never mistaken for one: a dashed border, a box icon instead of a repair
 * status icon, and no screen-side bar. Its category and status are in the
 * accessible name, never in colour alone.
 */
export const EquipmentNode = memo(function EquipmentNode({
  id,
  name,
  category,
  status,
  x,
  y,
  rotation,
  size,
  selected,
  invalid,
  dimmed,
  interactive,
  showLabel,
  onPointerDown,
  onKeyDown,
}: EquipmentNodeProps) {
  const tone = TONE[status.tone] ?? TONE.neutral
  const half = size / 2
  const icon = size * 0.5
  const label = `${name}${category ? `, ${category}` : ''}, ${status.label}, at x ${Math.round(x)}, y ${Math.round(y)}${
    rotation ? `, turned ${rotation}°` : ''
  }`

  return (
    <g
      data-testid="floor-plan-equipment"
      data-equipment-id={id}
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
      <rect
        className={cn(tone.tile, invalid && 'stroke-danger')}
        x={-half}
        y={-half}
        width={size}
        height={size}
        rx={6}
        strokeWidth={invalid ? 3 : 2}
        strokeDasharray={invalid ? undefined : '4 3'}
        vectorEffect="non-scaling-stroke"
        transform={rotation ? `rotate(${rotation})` : undefined}
      />
      <Package
        aria-hidden="true"
        className={tone.ink}
        x={-icon / 2}
        y={-icon / 2}
        width={icon}
        height={icon}
        strokeWidth={2.2}
      />
      {selected && (
        <rect
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

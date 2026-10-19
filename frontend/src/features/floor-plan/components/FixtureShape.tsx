import { memo } from 'react'
import { cn } from '@/lib/cn'
import { FIXTURES } from '../lib/fixtures'
import type { Fixture } from '../types'

interface FixtureShapeProps {
  fixture: Fixture
  selected: boolean
  invalid: boolean
  interactive: boolean
  showLabel: boolean
  onPointerDown?: (event: React.PointerEvent<SVGGElement>) => void
  onKeyDown?: (event: React.KeyboardEvent<SVGGElement>) => void
}

/**
 * A desk, wall, door… on the Floorplan (Phase 2.11, SDD DD-102). Neutral
 * tones: fixtures are the room, the status colours belong to the machines.
 * Only focusable while editing — in view mode they are the backdrop, and a
 * keyboard user should not have to tab through every wall to reach a PC.
 */
export const FixtureShape = memo(function FixtureShape({
  fixture,
  selected,
  invalid,
  interactive,
  showLabel,
  onPointerDown,
  onKeyDown,
}: FixtureShapeProps) {
  const preset = FIXTURES[fixture.kind]
  const { width: w, height: h } = fixture
  const name = fixture.label ? `${preset.label} “${fixture.label}”` : preset.label
  const label = `${name}, ${Math.round(w)} by ${Math.round(h)}, at x ${Math.round(fixture.x)}, y ${Math.round(fixture.y)}`
  const fontSize = Math.max(8, Math.min(18, Math.min(w, h) * 0.32))

  return (
    <g
      data-testid="floor-plan-fixture"
      data-fixture-id={fixture.id}
      data-kind={fixture.kind}
      transform={`translate(${fixture.x} ${fixture.y}) rotate(${fixture.rotation})`}
      role={interactive ? 'button' : undefined}
      tabIndex={interactive ? 0 : undefined}
      aria-label={interactive ? label : undefined}
      aria-pressed={interactive ? selected : undefined}
      aria-hidden={interactive ? undefined : true}
      aria-invalid={invalid || undefined}
      className={cn(
        'outline-none [&:focus-visible>.focus-ring]:opacity-100',
        interactive ? 'cursor-grab active:cursor-grabbing' : 'pointer-events-none',
      )}
      style={interactive ? { touchAction: 'none' } : undefined}
      onPointerDown={onPointerDown}
      onKeyDown={onKeyDown}
    >
      {interactive && <title>{label}</title>}
      <rect
        className="focus-ring fill-none stroke-focus opacity-0"
        x={-w / 2 - 5}
        y={-h / 2 - 5}
        width={w + 10}
        height={h + 10}
        rx={4}
        strokeWidth={2.5}
        vectorEffect="non-scaling-stroke"
      />
      <rect
        className={cn(preset.shape, invalid && 'stroke-danger')}
        x={-w / 2}
        y={-h / 2}
        width={w}
        height={h}
        rx={fixture.kind === 'pillar' ? Math.min(w, h) / 2 : 2}
        strokeWidth={invalid ? 3 : 1.5}
        vectorEffect="non-scaling-stroke"
      />
      {showLabel && fixture.label && (
        <text
          aria-hidden="true"
          className="pointer-events-none fill-muted select-none"
          textAnchor="middle"
          dominantBaseline="central"
          fontSize={fontSize}
        >
          {fixture.label}
        </text>
      )}
      {selected && (
        <rect
          data-testid="floor-plan-selection"
          className="pointer-events-none fill-none stroke-primary"
          x={-w / 2 - 3}
          y={-h / 2 - 3}
          width={w + 6}
          height={h + 6}
          rx={3}
          strokeWidth={2}
          strokeDasharray="5 3"
          vectorEffect="non-scaling-stroke"
        />
      )}
    </g>
  )
})

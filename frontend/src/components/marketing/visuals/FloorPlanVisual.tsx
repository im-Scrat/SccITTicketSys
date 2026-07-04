import { Monitor } from 'lucide-react'
import { cn } from '@/lib/cn'
import { StatusPill, type AssetStatus } from '@/components/ui/StatusPill'

/**
 * A floor-plan canvas fragment: PC units positioned on a room grid and colored
 * by the universal status palette, with a legend. Status is never color-only —
 * each node carries a shape/icon and the legend spells out every state
 * (FR-FP-004). Presentational; exposed as a single labelled image.
 */

interface Node {
  id: string
  x: number
  y: number
  status: AssetStatus
  selected?: boolean
}

const nodes: Node[] = [
  { id: 'PC-01', x: 10, y: 18, status: 'online' },
  { id: 'PC-02', x: 30, y: 18, status: 'online' },
  { id: 'PC-03', x: 50, y: 18, status: 'assigned' },
  { id: 'PC-04', x: 70, y: 18, status: 'online' },
  { id: 'PC-05', x: 90, y: 18, status: 'available' },
  { id: 'PC-06', x: 10, y: 52, status: 'online' },
  { id: 'PC-07', x: 30, y: 52, status: 'maintenance', selected: true },
  { id: 'PC-08', x: 50, y: 52, status: 'online' },
  { id: 'PC-09', x: 70, y: 52, status: 'offline' },
  { id: 'PC-10', x: 90, y: 52, status: 'online' },
  { id: 'PC-11', x: 20, y: 84, status: 'available' },
  { id: 'PC-12', x: 40, y: 84, status: 'assigned' },
  { id: 'PC-13', x: 60, y: 84, status: 'online' },
  { id: 'PC-14', x: 80, y: 84, status: 'online' },
]

const nodeTone: Record<AssetStatus, string> = {
  online: 'bg-success-subtle text-success-strong',
  offline: 'bg-danger-subtle text-danger-strong',
  maintenance: 'bg-warning-subtle text-warning-strong',
  assigned: 'bg-primary-subtle text-primary-strong',
  available: 'bg-surface-sunken text-muted',
}

const legend: AssetStatus[] = ['online', 'assigned', 'maintenance', 'offline', 'available']

export function FloorPlanVisual({ className }: { className?: string }) {
  return (
    <figure
      role="img"
      aria-label="A lab floor plan with fourteen PC units positioned on a grid, each colored by status: online, assigned, under maintenance, offline, or available."
      className={cn('overflow-hidden rounded-lg border border-border bg-surface', className)}
    >
      <div className="flex items-center justify-between border-b border-border px-4 py-2.5">
        <span className="text-sm font-semibold text-ink-strong">Lab 2 — Ground floor</span>
        <span className="slashed-zero font-mono text-[11px] text-muted">14 units · 1 alert</span>
      </div>

      <div
        className="relative aspect-[16/10] w-full"
        style={{
          backgroundImage:
            'linear-gradient(var(--grid-line) 1px, transparent 1px), linear-gradient(90deg, var(--grid-line) 1px, transparent 1px)',
          backgroundSize: '28px 28px',
          backgroundPosition: 'center',
        }}
      >
        {nodes.map((node) => (
          <div
            key={node.id}
            className="absolute -translate-x-1/2 -translate-y-1/2"
            style={{ left: `${node.x}%`, top: `${node.y}%` }}
          >
            <div
              className={cn(
                'flex size-8 items-center justify-center rounded-md border transition-transform duration-150 hover:scale-110',
                nodeTone[node.status],
                node.selected ? 'border-primary ring-2 ring-primary/40' : 'border-border',
              )}
            >
              <Monitor size={15} aria-hidden="true" />
            </div>
            {node.selected && (
              <div className="absolute left-1/2 top-full z-10 mt-2 w-max -translate-x-1/2 rounded-md border border-border bg-surface px-2.5 py-1.5 shadow-[var(--shadow-overlay-sm)]">
                <p className="slashed-zero font-mono text-[11px] font-medium text-ink-strong">
                  LAB2-PC-07
                </p>
                <p className="mt-0.5 text-[11px] text-warning-strong">
                  Under maintenance · Ticket #4820
                </p>
              </div>
            )}
          </div>
        ))}
      </div>

      <div className="flex flex-wrap gap-2 border-t border-border px-4 py-3">
        {legend.map((status) => (
          <StatusPill key={status} status={status} />
        ))}
      </div>
    </figure>
  )
}

import { Monitor } from 'lucide-react'
import { PcStatusBadge } from '@/components/ui/PcStatusBadge'
import { cn } from '@/lib/cn'
import { PC_STATUS, PC_STATUS_TONE_CLASSES, type PcStatusValue } from '@/lib/pcStatus'

/**
 * A Floorplan canvas fragment: PC units positioned on a room grid and colored
 * by the product's three PC statuses (Phase 2.11, DD-100), with a legend.
 * Status is never color-only — the legend spells out every state (FR-FP-004).
 * Presentational; exposed as a single labelled image.
 */

interface Node {
  id: string
  x: number
  y: number
  status: PcStatusValue
  selected?: boolean
}

const nodes: Node[] = [
  { id: 'PC-01', x: 10, y: 18, status: 'available' },
  { id: 'PC-02', x: 30, y: 18, status: 'available' },
  { id: 'PC-03', x: 50, y: 18, status: 'available' },
  { id: 'PC-04', x: 70, y: 18, status: 'available' },
  { id: 'PC-05', x: 90, y: 18, status: 'available' },
  { id: 'PC-06', x: 10, y: 52, status: 'available' },
  { id: 'PC-07', x: 30, y: 52, status: 'pending_repair', selected: true },
  { id: 'PC-08', x: 50, y: 52, status: 'available' },
  { id: 'PC-09', x: 70, y: 52, status: 'needs_fix' },
  { id: 'PC-10', x: 90, y: 52, status: 'available' },
  { id: 'PC-11', x: 20, y: 84, status: 'available' },
  { id: 'PC-12', x: 40, y: 84, status: 'available' },
  { id: 'PC-13', x: 60, y: 84, status: 'available' },
  { id: 'PC-14', x: 80, y: 84, status: 'available' },
]

const legend: PcStatusValue[] = ['available', 'pending_repair', 'needs_fix']

export function FloorPlanVisual({ className }: { className?: string }) {
  return (
    <figure
      role="img"
      aria-label="A lab Floorplan with fourteen PC units positioned on a grid, each colored by status: available, pending repair, or needs fix."
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
                PC_STATUS_TONE_CLASSES[PC_STATUS[node.status].tone].wash,
                PC_STATUS_TONE_CLASSES[PC_STATUS[node.status].tone].text,
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
                  Pending Repair · Ticket #4820
                </p>
              </div>
            )}
          </div>
        ))}
      </div>

      <div className="flex flex-wrap gap-2 border-t border-border px-4 py-3">
        {legend.map((status) => (
          <PcStatusBadge key={status} status={status} />
        ))}
      </div>
    </figure>
  )
}

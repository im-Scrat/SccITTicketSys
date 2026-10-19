import { ChevronRight } from 'lucide-react'
import { cn } from '@/lib/cn'
import { PC_STATUS, PC_STATUS_TONE_CLASSES, type PcStatusCategory } from '@/lib/pcStatus'
import type { DistributionRow } from '../types'

const ORDER: PcStatusCategory[] = ['available', 'pending_repair', 'needs_fix', 'unassigned']

/**
 * Phase 2.11 — the four PC status categories at a glance (SDD DD-100).
 *
 * Three repair statuses partition the live estate; "Unassigned" (no room) is
 * shown alongside but overlaps them — an unassigned machine still has a repair
 * status — which the caption says rather than leaving the sum to mislead.
 * Every tile opens the PC directory with that filter applied.
 */
export function PcStatusSummary({
  rows,
  onSelect,
}: {
  rows: DistributionRow[]
  onSelect: (key: PcStatusCategory) => void
}) {
  const counts = new Map(rows.map((row) => [row.key, row.count]))

  return (
    <section aria-labelledby="pc-status-summary" className="flex flex-col gap-4">
      <div>
        <h2 id="pc-status-summary" className="text-lg font-bold text-ink-strong">
          PC status
        </h2>
        <p className="mt-1 text-sm text-muted">
          Repair status as recorded by staff, tickets and repairs. Unassigned PCs have no room and
          are also counted in their repair status.
        </p>
      </div>
      <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {ORDER.map((key) => {
          const status = PC_STATUS[key]
          const tone = PC_STATUS_TONE_CLASSES[status.tone]
          const Icon = status.icon
          const count = counts.get(key) ?? 0

          return (
            <li key={key}>
              <button
                type="button"
                onClick={() => onSelect(key)}
                className={cn(
                  'group flex w-full items-center gap-4 rounded-md border bg-surface p-5 text-left transition-colors hover:bg-surface-sunken focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus',
                  tone.border,
                )}
              >
                <span
                  className={cn('grid size-11 shrink-0 place-items-center rounded-md', tone.wash)}
                  aria-hidden="true"
                >
                  <Icon className={cn('size-5', tone.text)} strokeWidth={2.25} />
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block text-sm font-medium text-muted">{status.label}</span>
                  <span className={cn('block text-2xl font-bold tabular-nums', tone.text)}>
                    {count}
                  </span>
                </span>
                <ChevronRight
                  aria-hidden="true"
                  className="size-5 text-faint transition-transform group-hover:translate-x-0.5 motion-reduce:transition-none"
                />
                <span className="sr-only">— view these PCs</span>
              </button>
            </li>
          )
        })}
      </ul>
    </section>
  )
}

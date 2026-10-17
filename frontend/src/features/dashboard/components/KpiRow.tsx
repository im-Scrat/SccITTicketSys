import { StatCard, type StatTone } from '@/components/ui'
import type { KpiItem } from '../types'

/**
 * Column classes by item count, as literal strings so Tailwind can see them.
 *
 * The grid answers to **its own width** (`@container`), not the viewport's. The
 * same row sits in a full-width lead section and in a 24rem aside; sizing it by
 * viewport gave the aside four ~80px columns on a wide screen and clipped every
 * label. The count picks a layout that fills its last row: three cards are never
 * left as "two and one", and two cards never stretch across four columns.
 */
const COLUMNS: Record<number, string> = {
  1: 'grid-cols-1',
  2: 'grid-cols-1 @sm:grid-cols-2',
  3: 'grid-cols-1 @md:grid-cols-3',
  4: 'grid-cols-2 @2xl:grid-cols-4',
}

const FALLBACK_COLUMNS = 'grid-cols-2 @2xl:grid-cols-4'

/**
 * A row of headline figures — the right form for "a handful of numbers", instead
 * of a chart that would say the same thing with more ink.
 *
 * `tone` is only ever a **status** signal (something is breached, something needs
 * attention), never identity, and the label always states what the number is, so
 * the meaning survives colour-blindness and grayscale. A figure with an `href` is
 * a link to the list it counts — a real link, including when the figure is zero,
 * so "nothing is overdue" is something you can open and see.
 */
export function KpiRow({ items }: { items: KpiItem[] }) {
  return (
    <div className="@container">
      <ul className={`grid gap-3 ${COLUMNS[items.length] ?? FALLBACK_COLUMNS}`}>
        {items.map((item) => (
          <li key={item.key} className="min-w-0">
            <StatCard
              label={item.label}
              value={item.value.toLocaleString()}
              tone={(item.tone ?? 'neutral') as StatTone}
              to={item.href}
            />
          </li>
        ))}
      </ul>
    </div>
  )
}

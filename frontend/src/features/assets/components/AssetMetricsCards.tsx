import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'
import type { DistributionRow, Tone } from '../types'

interface MetricCardProps {
  label: string
  value: ReactNode
  hint?: string
  tone?: Tone
  /** Navigates to the directory with this card's filter applied. */
  onClick?: () => void
}

const toneValue: Record<Tone, string> = {
  neutral: 'text-ink-strong',
  success: 'text-success-strong',
  warning: 'text-warning-strong',
  danger: 'text-danger-strong',
}

/**
 * One dashboard figure.
 *
 * Every card that can be filtered on **is a button** that navigates to the
 * directory with that filter applied — a count you cannot act on is decoration.
 * Cards without a meaningful filter render as static blocks rather than as
 * buttons that do nothing, so the affordance never lies.
 */
export function MetricCard({ label, value, hint, tone = 'neutral', onClick }: MetricCardProps) {
  const content = (
    <>
      <span className="text-sm font-semibold text-muted">{label}</span>
      <span className={cn('mt-2 block text-2xl font-bold tabular-nums', toneValue[tone])}>
        {value}
      </span>
      {hint && <span className="mt-1 block text-xs text-muted">{hint}</span>}
    </>
  )

  const shell = 'rounded-lg border-2 border-border bg-surface p-6 text-left'

  if (!onClick) {
    return <div className={shell}>{content}</div>
  }

  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        shell,
        'min-h-[7.5rem] transition-colors hover:border-primary hover:bg-primary-subtle',
      )}
    >
      {content}
    </button>
  )
}

/**
 * A labelled proportional distribution whose markup *is* the data table
 * (SDD DD-30): the accessible reading and the visual reading are the same
 * object, in a single hue, with no charting dependency.
 *
 * Rows carrying a `key` are navigable — clicking "Science Hall · 42" opens the
 * directory filtered to that building.
 */
export function DistributionCard({
  title,
  rows,
  unit,
  empty,
  onSelect,
}: {
  title: string
  rows: DistributionRow[]
  unit: string
  empty: string
  onSelect?: (row: DistributionRow) => void
}) {
  const total = rows.reduce((sum, row) => sum + row.count, 0)
  const max = rows.reduce((peak, row) => Math.max(peak, row.count), 0)

  return (
    <section className="rounded-lg border-2 border-border bg-surface p-6">
      <h3 className="text-base font-bold text-ink-strong">{title}</h3>

      {rows.length === 0 ? (
        <p className="mt-4 text-sm text-muted">{empty}</p>
      ) : (
        <table className="mt-4 w-full">
          <caption className="sr-only">
            {title} — {total} {unit} in total
          </caption>
          <tbody>
            {rows.map((row) => {
              const width = max === 0 ? 0 : Math.round((row.count / max) * 100)
              const cell = (
                <>
                  <span className="relative z-10">{row.label}</span>
                  <span
                    className="absolute inset-y-0 left-0 rounded-sm bg-primary-subtle"
                    style={{ width: `${width}%` }}
                    aria-hidden="true"
                  />
                </>
              )

              return (
                <tr key={row.key ?? row.label}>
                  <th
                    scope="row"
                    className="relative w-full py-2 pr-4 text-left text-sm font-medium text-ink"
                  >
                    {onSelect && row.key ? (
                      <button
                        type="button"
                        onClick={() => onSelect(row)}
                        className="relative w-full rounded-sm py-1 text-left underline-offset-4 hover:underline"
                      >
                        {cell}
                      </button>
                    ) : (
                      cell
                    )}
                  </th>
                  <td className="py-2 text-right text-sm font-bold tabular-nums text-ink-strong">
                    {row.count}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      )}
    </section>
  )
}

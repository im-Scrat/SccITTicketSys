import { cn } from '@/lib/cn'

interface ProgressBarProps {
  /** Work done so far; omit for an indeterminate bar (the total is not known yet). */
  value?: number
  max?: number
  /** Names the bar ("Importing records"). */
  label: string
  /** Visible and announced value, e.g. "48 of 120"; defaults to a percentage. */
  valueText?: string
  /** `warning` for a run that stopped or partly failed — never red (Reserved-Red). */
  tone?: 'primary' | 'success' | 'warning'
  className?: string
}

const fills = {
  primary: 'bg-primary',
  success: 'bg-success',
  warning: 'bg-warning',
}

/**
 * Determinate progress for long-running work the user is waiting on (an
 * import, a large export). The label and the "N of M" text are always shown,
 * so progress never depends on reading the bar's length or colour.
 *
 * The fill is sized with a transform through React's style prop (the CSSOM),
 * which the production CSP allows; it animates the transform only, and the
 * global reduced-motion rule makes it instant.
 */
export function ProgressBar({
  value,
  max = 100,
  label,
  valueText,
  tone = 'primary',
  className,
}: ProgressBarProps) {
  const determinate = value !== undefined && max > 0
  const ratio = determinate ? Math.min(Math.max(value / max, 0), 1) : 0
  const text = valueText ?? (determinate ? `${Math.round(ratio * 100)}%` : undefined)

  return (
    <div className={cn('flex flex-col gap-2', className)}>
      <div className="flex items-baseline justify-between gap-4 text-sm">
        <span className="font-semibold text-ink">{label}</span>
        {text && <span className="tabular-nums text-muted">{text}</span>}
      </div>
      <div
        role="progressbar"
        aria-label={label}
        aria-valuemin={determinate ? 0 : undefined}
        aria-valuemax={determinate ? max : undefined}
        aria-valuenow={determinate ? value : undefined}
        aria-valuetext={determinate ? text : undefined}
        className="h-3 w-full overflow-hidden rounded-full bg-surface-sunken"
      >
        <div
          className={cn(
            'h-full w-full origin-left rounded-full transition-transform duration-300 [transition-timing-function:var(--ease-standard)]',
            fills[tone],
            !determinate && 'opacity-40',
          )}
          style={{ transform: `scaleX(${determinate ? ratio : 1})` }}
        />
      </div>
    </div>
  )
}

import { cn } from '@/lib/cn'

interface SpinnerProps {
  className?: string
  /** Diameter in px. */
  size?: number
  /** Accessible label; omit for purely decorative spinners (e.g. inside a button). */
  label?: string
}

/**
 * Indeterminate progress ring. Uses currentColor so it inherits the surrounding
 * text color. Under reduced-motion the global rule freezes the spin (it becomes
 * a static ring) — acceptable, and never the sole indicator of progress.
 */
export function Spinner({ className, size = 16, label }: SpinnerProps) {
  return (
    <svg
      className={cn('animate-spin', className)}
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      role={label ? 'status' : undefined}
      aria-hidden={label ? undefined : true}
      aria-label={label}
    >
      <circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity="0.25" strokeWidth="2.5" />
      <path
        d="M21 12a9 9 0 0 0-9-9"
        stroke="currentColor"
        strokeWidth="2.5"
        strokeLinecap="round"
      />
    </svg>
  )
}

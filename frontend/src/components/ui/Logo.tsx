import { cn } from '@/lib/cn'

interface LogoProps {
  className?: string
  /** Mark size in px. */
  size?: number
  /** Render the "SccIT" wordmark next to the mark. */
  withWordmark?: boolean
}

/**
 * Brand lockup: the SccIT mark (a QR-style finder tile in Signal Blue — nodding
 * to the QR-tracked-asset heart of the product) plus an optional wordmark.
 * The mark is brand-blue in both themes; the wordmark follows the ink token.
 */
export function Logo({ className, size = 28, withWordmark = true }: LogoProps) {
  return (
    <span className={cn('inline-flex items-center gap-2.5', className)}>
      <svg
        width={size}
        height={size}
        viewBox="0 0 32 32"
        role="img"
        aria-label="SccIT"
        className="shrink-0"
      >
        <rect width="32" height="32" rx="7" className="fill-primary" />
        <rect
          x="8"
          y="8"
          width="16"
          height="16"
          rx="3.5"
          fill="none"
          className="stroke-white"
          strokeWidth="2.5"
        />
        <rect x="12.5" y="12.5" width="7" height="7" rx="1.75" className="fill-white" />
      </svg>
      {withWordmark && (
        <span className="text-[1.0625rem] font-semibold tracking-[-0.01em] text-ink-strong">
          SccIT
        </span>
      )}
    </span>
  )
}

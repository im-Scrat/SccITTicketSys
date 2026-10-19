import type { ReactNode } from 'react'
import { useElementWidth } from '@/hooks/useElementWidth'
import { cn } from '@/lib/cn'

interface ChartFrameProps {
  /** Height reserved for the drawing, so the page never jumps when it measures. */
  height: number
  /** What the chart shows, in a sentence, for assistive technology. */
  label: string
  /** Draws at the measured width; not called until there is one. */
  children: (width: number) => ReactNode
  /** The same data as a table — every chart has one (WCAG 1.1.1). */
  table?: ReactNode
  tableLabel?: string
  className?: string
}

/**
 * The box every chart draws into: it measures its own width (charts size from
 * their panel, not the viewport), reserves its height, names the drawing, and
 * offers the data as a table under a disclosure. The SVG itself is hidden from
 * assistive technology in favour of that table, which reads the same numbers.
 */
export function ChartFrame({
  height,
  label,
  children,
  table,
  tableLabel = 'Show the figures as a table',
  className,
}: ChartFrameProps) {
  const { ref, width } = useElementWidth()

  return (
    <figure className={cn('flex min-w-0 flex-col gap-3', className)}>
      <div ref={ref} role="img" aria-label={label} className="w-full min-w-0" style={{ height }}>
        {width > 0 && <div aria-hidden="true">{children(width)}</div>}
      </div>
      {table && (
        <details className="group text-sm">
          <summary className="w-fit cursor-pointer rounded-sm font-semibold text-primary-strong">
            {tableLabel}
          </summary>
          <div className="mt-3 overflow-x-auto">{table}</div>
        </details>
      )}
    </figure>
  )
}

import { cn } from '@/lib/cn'
import { useIsTruncated } from '@/hooks/useIsTruncated'
import { Tooltip } from './Tooltip'

interface TruncatedTextProps {
  text: string
  /** 1 = a single line with an ellipsis; 2–3 = a line clamp. */
  lines?: 1 | 2 | 3
  className?: string
}

const clamp = {
  1: 'truncate',
  2: 'line-clamp-2',
  3: 'line-clamp-3',
}

/**
 * Text cut to fit where alignment matters — a table cell, a legend row, a chip.
 * Elsewhere text wraps; this is the exception, not the default (DESIGN.md).
 *
 * The full string is always in the DOM, so screen readers and find-in-page get
 * all of it. Only when the text is actually cut off does it become focusable
 * and gain a tooltip with the rest, so keyboard and pointer users can read it
 * too (WCAG 1.4.13) — and no extra Tab stops appear for text that fits.
 */
export function TruncatedText({ text, lines = 1, className }: TruncatedTextProps) {
  const { ref, truncated } = useIsTruncated<HTMLSpanElement>(text)

  const span = (
    <span
      ref={ref}
      tabIndex={truncated ? 0 : undefined}
      className={cn(lines === 1 ? 'block' : 'block break-words', clamp[lines], className)}
    >
      {text}
    </span>
  )

  if (!truncated) return span

  return (
    <Tooltip content={text} describe={false}>
      {span}
    </Tooltip>
  )
}

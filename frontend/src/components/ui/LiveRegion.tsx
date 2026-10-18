import { useEffect, useState } from 'react'
import { cn } from '@/lib/cn'

interface LiveRegionProps {
  /** The latest announcement; an empty string announces nothing. */
  message: string
  /** `polite` waits for a pause (results, progress); `assertive` interrupts (failures). */
  politeness?: 'polite' | 'assertive'
  /**
   * Bump to re-announce an unchanged message ("Saved" twice in a row), which
   * a screen reader would otherwise ignore as no change.
   */
  announceKey?: string | number
  /** Show the text on screen too; hidden by default. */
  visible?: boolean
  className?: string
}

/**
 * A status region for results that arrive without a focus change — "12 rows
 * ready, 3 need a decision", "Slide 2 of 5", "Import finished".
 *
 * The region element is mounted before the first message and stays mounted:
 * screen readers only announce changes to a region they already know about,
 * so one that appears together with its text is often silent. Each message is
 * written a moment after the region is cleared, so repeats are announced.
 */
export function LiveRegion({
  message,
  politeness = 'polite',
  announceKey,
  visible = false,
  className,
}: LiveRegionProps) {
  const [text, setText] = useState('')

  useEffect(() => {
    setText('')
    if (!message) return
    const timer = setTimeout(() => setText(message), 60)
    return () => clearTimeout(timer)
  }, [message, announceKey])

  return (
    <div
      role={politeness === 'assertive' ? 'alert' : 'status'}
      aria-live={politeness}
      aria-atomic="true"
      className={cn(!visible && 'sr-only', className)}
    >
      {text}
    </div>
  )
}

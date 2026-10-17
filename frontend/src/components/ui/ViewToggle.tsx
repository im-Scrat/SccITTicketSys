import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'

/**
 * One choice in a row of saved views (My tickets, My work). A toggle button group
 * (`aria-pressed`), not tabs: choosing one re-filters the list below it rather
 * than switching panels, and `Tabs` would announce a tab pattern this does not
 * implement.
 *
 * Active is a fill, a border and a ring — never colour alone — and the weight is
 * the same either way so toggling does not shift its neighbours. Classes are
 * composed, not layered: `cn()` here has no tailwind-merge, so two border colours
 * would be decided by stylesheet order, not by the order written.
 *
 * Extracted from MyTicketsPage unchanged so the technician's My work page shares
 * the exact control instead of a lookalike.
 */
export function ViewToggle({
  active,
  onClick,
  children,
}: {
  active: boolean
  onClick: () => void
  children: ReactNode
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={cn(
        // The shared secondary Button's own weight and shadow, so the row reads as
        // part of the same control family as the buttons beside it.
        'inline-flex h-12 items-center justify-center rounded-md border px-4 text-sm font-semibold whitespace-nowrap shadow-sm transition-colors duration-150',
        active
          ? 'border-primary bg-primary-subtle text-primary-strong ring-1 ring-primary'
          : 'border-control-border bg-surface text-ink hover:bg-surface-sunken',
      )}
    >
      {children}
    </button>
  )
}

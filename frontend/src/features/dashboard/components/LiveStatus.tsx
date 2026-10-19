import { cn } from '@/lib/cn'

/**
 * Phase 2.9 — says honestly whether the page is updating by itself.
 *
 * Text, not a colored dot alone (state is never color-only). The dot is the
 * one place the success hue appears on the header, and it does not pulse:
 * motion would add nothing a word does not already say.
 */
export function LiveStatus({ live, compact = false }: { live: boolean; compact?: boolean }) {
  // `compact` is for a dense header (the Floorplan editor), which refreshes on
  // its own when not live, so the "use Refresh" advice does not apply there.
  const full = live ? 'Live — updates as things change' : 'Not live — use Refresh'
  return (
    <span
      className="inline-flex items-center gap-2 rounded-full border border-border bg-surface px-3 py-1 text-xs font-medium text-muted"
      role="status"
      title={
        compact
          ? live
            ? 'Updates as things change'
            : 'Checks for changes now and then'
          : undefined
      }
    >
      <span
        aria-hidden="true"
        className={cn('size-2 rounded-full', live ? 'bg-success' : 'bg-faint')}
      />
      {compact ? (live ? 'Live' : 'Not live') : full}
    </span>
  )
}

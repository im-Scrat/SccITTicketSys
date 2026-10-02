import { cn } from '@/lib/cn'

/**
 * Phase 2.9 — says honestly whether the page is updating by itself.
 *
 * Text, not a colored dot alone (state is never color-only). The dot is the
 * one place the success hue appears on the header, and it does not pulse:
 * motion would add nothing a word does not already say.
 */
export function LiveStatus({ live }: { live: boolean }) {
  return (
    <span
      className="inline-flex items-center gap-2 rounded-full border border-border bg-surface px-3 py-1 text-xs font-medium text-muted"
      role="status"
    >
      <span
        aria-hidden="true"
        className={cn('size-2 rounded-full', live ? 'bg-success' : 'bg-faint')}
      />
      {live ? 'Live — updates as things change' : 'Not live — use Refresh'}
    </span>
  )
}

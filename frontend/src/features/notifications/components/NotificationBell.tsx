import { Bell } from 'lucide-react'
import { forwardRef } from 'react'
import { cn } from '@/lib/cn'

interface NotificationBellProps {
  unreadCount: number
  open: boolean
  onToggle: () => void
  /** Ties the button to the panel it discloses. */
  panelId: string
  className?: string
}

/** Above this, the badge stops counting and starts saying "a lot". */
const BADGE_CAP = 99

/**
 * The header notification entry (SRS FR-NOT-001, NFR-ACC-004/009).
 *
 * ── The badge is blue, and that is a rule rather than a preference ─────────
 *
 * DESIGN.md's Reserved-Red Rule: *"Red is only destructive, critical, or
 * offline. It is never a brand color, decorative accent, or emphasis."* The
 * conventional red notification dot is therefore banned by this project's own
 * design system — a red that does not mean danger is a bug. Signal Blue is the
 * documented indicator hue for "current selection, active state", and the badge
 * is the literal one-indicator-light the design brief asks for.
 *
 * ── Absent at zero ────────────────────────────────────────────────────────
 *
 * An indicator that is always lit means nothing. At zero there is no badge and
 * the accessible name reverts to plain "Notifications", so the control's own
 * appearance is the answer to "is there anything for me?".
 *
 * ── The count has to be in the name, not only in the pixels ───────────────
 *
 * A visual badge is invisible to a screen reader. The button's accessible name
 * therefore states the count — "Notifications, 3 unread" — which is also what
 * makes the state survive grayscale and colour-vision differences (NFR-ACC-004).
 * The cap keeps the control a fixed width so a hundredth notification cannot
 * reflow the header.
 *
 * Icon-only is justified here and nowhere else in this shell: `AppLayout`'s
 * navigation pairs every icon with a text label, but the header utility cluster
 * already holds `ThemeToggle`, an icon-only control with `aria-label` **and**
 * `title`. This follows that precedent rather than inventing a third pattern.
 */
export const NotificationBell = forwardRef<HTMLButtonElement, NotificationBellProps>(
  function NotificationBell({ unreadCount, open, onToggle, panelId, className }, ref) {
    const count = Math.max(0, unreadCount)
    const hasUnread = count > 0
    const badgeLabel = count > BADGE_CAP ? `${BADGE_CAP}+` : String(count)
    const accessibleName = hasUnread ? `Notifications, ${count} unread` : 'Notifications'

    return (
      <button
        ref={ref}
        type="button"
        onClick={onToggle}
        aria-label={accessibleName}
        title={accessibleName}
        aria-expanded={open}
        aria-controls={panelId}
        aria-haspopup="dialog"
        className={cn(
          // 36px clears NFR-ACC-009's 24×24 minimum comfortably, and matches
          // ThemeToggle beside it.
          'relative inline-flex size-9 items-center justify-center rounded-sm text-muted',
          'transition-colors duration-150 [transition-timing-function:var(--ease-standard)]',
          'hover:bg-surface-sunken hover:text-ink',
          open && 'bg-surface-sunken text-ink',
          className,
        )}
      >
        <Bell size={18} aria-hidden="true" />

        {hasUnread && (
          <span
            aria-hidden="true"
            className={cn(
              'absolute -right-1 -top-1 inline-flex min-w-[1.125rem] items-center justify-center',
              'rounded-full border border-surface bg-primary px-1 py-px',
              'text-[0.6875rem] font-bold leading-none text-on-primary tnum',
            )}
          >
            {badgeLabel}
          </span>
        )}
      </button>
    )
  },
)

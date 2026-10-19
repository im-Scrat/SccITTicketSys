import { Link } from 'react-router-dom'
import { Badge } from '@/components/ui/Badge'
import { cn } from '@/lib/cn'
import { formatDateTime, formatRelative } from '@/lib/datetime'
import {
  notificationIcon,
  notificationTone,
  notificationTypeLabel,
  safeActionPath,
} from '../lib/presentation'
import type { AppNotification, Option } from '../types'

interface NotificationRowProps {
  notification: AppNotification
  /** The server's type vocabulary, so the badge is labelled by the API. */
  typeOptions?: Option[]
  /** Fired by the row's own control and by following its link. */
  onMarkRead: (id: string) => void
  onMarkUnread: (id: string) => void
  /** Closes the panel when a row inside it navigates. */
  onNavigate?: () => void
  /** Suppresses the read/unread control where there is no room for it. */
  compact?: boolean
}

/**
 * One notification (SRS FR-NOT-001/004).
 *
 * ── Unread is never carried by colour alone ────────────────────────────────
 *
 * NFR-ACC-004 and the design system both forbid it, so unread is stated three
 * times over: a leading dot, a semibold title, and the words "Unread" in text
 * that is available to a screen reader. Any one of the three can be lost — to
 * grayscale, to a colour-vision difference, to a stylesheet that did not
 * load — and the state still reads.
 *
 * ── The link is conditional, and that is a requirement, not a nicety ───────
 *
 * `action_url` is nullable by design: `account.locked` deliberately has no
 * destination, because the only place to send someone is the sign-in page they
 * were just refused. A future announcement may have none either. So a row with
 * no destination renders as a row, not as a link to nowhere — and the URL is
 * put through {@link safeActionPath} first, which refuses anything that is not
 * an internal `/app/` path.
 *
 * ── Why this is not a card ─────────────────────────────────────────────────
 *
 * The list is the panel; the rows are its contents. Wrapping each one in its
 * own bordered surface would nest a card inside a card, which the Flat-Plane
 * Rule forbids and which turns a scannable list into a stack of boxes. Rows are
 * separated by a hairline and by tone on hover, the same way every other list
 * in this console separates them.
 */
export function NotificationRow({
  notification,
  typeOptions = [],
  onMarkRead,
  onMarkUnread,
  onNavigate,
  compact = false,
}: NotificationRowProps) {
  const { id, type, topic, title, message, is_read: isRead, created_at: createdAt } = notification

  const Icon = notificationIcon(topic, type)
  const destination = safeActionPath(notification.action_url)
  const typeLabel = notificationTypeLabel(type, typeOptions)
  const absolute = formatDateTime(createdAt)

  /**
   * Following a notification marks it read.
   *
   * Optimistically, and without waiting: the navigation happens either way, and
   * a person who has just left the list should not be held there by a request.
   * If it fails the mutation rolls the row back, and the row they return to
   * still says unread — which is the truthful outcome.
   */
  const follow = () => {
    if (!isRead) onMarkRead(id)
    onNavigate?.()
  }

  return (
    <li
      className={cn(
        'group relative flex gap-3 px-4 py-3.5 transition-colors duration-150 [transition-timing-function:var(--ease-standard)]',
        'hover:bg-surface-sunken focus-within:bg-surface-sunken',
        !isRead && 'bg-primary-subtle/40',
      )}
    >
      {/* Marker column: the unread dot, or the type's glyph once it is read. */}
      <span className="mt-0.5 flex w-5 shrink-0 justify-center" aria-hidden="true">
        {isRead ? (
          <Icon size={18} className="text-muted" />
        ) : (
          <span className="mt-1.5 block size-2.5 rounded-full bg-primary" />
        )}
      </span>

      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-start gap-x-2 gap-y-1">
          <p
            className={cn(
              'min-w-0 flex-1 text-sm leading-snug',
              isRead ? 'font-medium text-ink' : 'font-semibold text-ink-strong',
            )}
          >
            {/*
             * The state, in words, before the title. Visually hidden because
             * the dot and the weight already say it on screen — but a screen
             * reader has neither, and "Unread." read first is what makes the
             * row's state audible rather than implied.
             */}
            <span className="sr-only">{isRead ? 'Read.' : 'Unread.'} </span>
            {title}
          </p>

          <Badge tone={notificationTone(type)} className="shrink-0">
            {typeLabel}
          </Badge>
        </div>

        {message && <p className="mt-1 text-sm leading-snug text-muted">{message}</p>}

        <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5">
          <time
            dateTime={createdAt ?? undefined}
            title={absolute}
            className="text-xs text-muted tnum"
          >
            {formatRelative(createdAt)}
          </time>

          {destination !== null && (
            <Link
              to={destination}
              onClick={follow}
              className="text-xs font-semibold text-primary-strong underline-offset-2 hover:underline"
            >
              Open
              <span className="sr-only"> — {title}</span>
            </Link>
          )}

          {!compact && (
            <button
              type="button"
              onClick={() => (isRead ? onMarkUnread(id) : onMarkRead(id))}
              className={cn(
                'rounded-sm px-2 py-1 text-xs font-semibold text-muted',
                'hover:bg-surface hover:text-ink',
              )}
            >
              {isRead ? 'Mark as unread' : 'Mark as read'}
              <span className="sr-only"> — {title}</span>
            </button>
          )}
        </div>
      </div>
    </li>
  )
}

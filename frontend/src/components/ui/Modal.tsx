import { X } from 'lucide-react'
import { type ReactNode, type RefObject, useCallback, useId, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { cn } from '@/lib/cn'
import { useOverlayLayer } from './overlayLayer'
import { OverlayRootContext } from './overlayRoot'

interface ModalProps {
  open: boolean
  onClose: () => void
  title?: ReactNode
  description?: ReactNode
  children: ReactNode
  footer?: ReactNode
  /**
   * `full` is a near-viewport dialog of fixed height (the post-import review);
   * its body is a flex column the content lays out itself.
   */
  size?: 'sm' | 'md' | 'lg' | 'xl' | 'full'
  /** Hide the header × control (e.g. a forced confirmation). */
  hideClose?: boolean
  /** Controls placed on the header row before ×, e.g. a slide counter. */
  headerExtra?: ReactNode
  /**
   * `padded` (default): a padded body that scrolls past 70% of the viewport.
   * `flush`: no padding, no own scrolling — the content manages both.
   */
  bodyLayout?: 'padded' | 'flush'
  /** Focus this element on open instead of the dialog. */
  initialFocusRef?: RefObject<HTMLElement | null>
  /** Return focus here on close instead of the element that opened it. */
  returnFocusRef?: RefObject<HTMLElement | null>
  /** A click on the dimmed backdrop closes the dialog (default true). */
  dismissOnBackdrop?: boolean
  /** The × control's accessible name. */
  closeLabel?: string
  /** Name the dialog when it has no visible title. */
  ariaLabel?: string
}

/* Widened with the type scale in Phase 2.5 — see the note in Drawer.tsx. */
const sizes = {
  sm: 'max-w-md',
  md: 'max-w-2xl',
  lg: 'max-w-4xl',
  xl: 'max-w-6xl',
  full: 'sm:w-[min(94vw,88rem)] sm:max-w-none h-[100dvh] sm:h-[min(92dvh,62rem)]',
}

/**
 * Centered modal dialog — a floating overlay, so it earns a shadow (Flat-Plane
 * Rule). role="dialog" + aria-modal with a labelled title; the scrim is a fixed
 * dark wash that reads correctly in both themes.
 *
 * Phase 2.10 (R2) made the modal promise real: focus moves in on open and is
 * **kept** in — Tab wraps at either end — and everything behind is `inert`
 * until it closes, then focus returns to the opener (or `returnFocusRef`).
 * Escape closes only the topmost overlay, Drawers included, through the one
 * shared stack in `overlayLayer`.
 */
export function Modal({
  open,
  onClose,
  title,
  description,
  children,
  footer,
  size = 'md',
  hideClose = false,
  headerExtra,
  bodyLayout = 'padded',
  initialFocusRef,
  returnFocusRef,
  dismissOnBackdrop = true,
  closeLabel = 'Close dialog',
  ariaLabel,
}: ModalProps) {
  const titleId = useId()
  const descId = useId()
  const rootRef = useRef<HTMLDivElement>(null)
  const dialogRef = useRef<HTMLDivElement>(null)
  // The root is also where Tooltips and Combobox lists inside portal to (see
  // overlayRoot.ts); state, so they re-render once it exists.
  const [overlayRoot, setOverlayRoot] = useState<HTMLDivElement | null>(null)
  const attachRoot = useCallback((element: HTMLDivElement | null) => {
    rootRef.current = element
    setOverlayRoot(element)
  }, [])

  useOverlayLayer({ open, onClose, rootRef, dialogRef, initialFocusRef, returnFocusRef })

  if (!open) return null

  const full = size === 'full'

  return createPortal(
    <div
      ref={attachRoot}
      className={cn(
        'fixed inset-0 z-50 flex justify-center overflow-y-auto',
        full ? 'items-center sm:p-4' : 'items-end p-0 sm:items-center sm:p-4',
      )}
    >
      <div
        className="fixed inset-0 bg-black/50 animate-fade-in"
        onClick={dismissOnBackdrop ? onClose : undefined}
        aria-hidden="true"
      />
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-label={title ? undefined : ariaLabel}
        aria-labelledby={title ? titleId : undefined}
        aria-describedby={description ? descId : undefined}
        // Focusable by script only, so it can receive focus on open without
        // becoming a Tab stop; the ring is suppressed because the dialog's own
        // edge already shows where focus is.
        tabIndex={-1}
        className={cn(
          'relative flex w-full flex-col border border-border bg-surface shadow-[var(--shadow-overlay-md)] outline-none animate-sheet-in focus-visible:outline-none',
          full ? 'rounded-none sm:rounded-lg' : 'rounded-t-lg sm:rounded-lg',
          sizes[size],
        )}
      >
        <OverlayRootContext value={overlayRoot}>
          {(title || headerExtra || !hideClose) && (
            <div className="flex shrink-0 items-start justify-between gap-4 border-b border-border px-5 py-3.5">
              <div className="min-w-0">
                {title && (
                  <h2 id={titleId} className="text-base font-semibold text-ink-strong">
                    {title}
                  </h2>
                )}
                {description && (
                  <p id={descId} className="mt-0.5 text-sm text-muted">
                    {description}
                  </p>
                )}
              </div>
              <div className="flex shrink-0 items-center gap-3">
                {headerExtra}
                {!hideClose && (
                  <button
                    type="button"
                    onClick={onClose}
                    aria-label={closeLabel}
                    className="-mr-1.5 -mt-0.5 rounded-sm p-1.5 text-muted hover:bg-surface-sunken hover:text-ink"
                  >
                    <X size={18} aria-hidden="true" />
                  </button>
                )}
              </div>
            </div>
          )}

          {bodyLayout === 'flush' ? (
            <div className="flex min-h-0 flex-1 flex-col">{children}</div>
          ) : (
            <div className="max-h-[70vh] overflow-y-auto px-5 py-4">{children}</div>
          )}

          {footer && (
            <div className="flex shrink-0 flex-wrap justify-end gap-2 border-t border-border px-5 py-3.5">
              {footer}
            </div>
          )}
        </OverlayRootContext>
      </div>
    </div>,
    document.body,
  )
}

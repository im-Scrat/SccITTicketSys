import { X } from 'lucide-react'
import { type ReactNode, type RefObject, useCallback, useId, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { cn } from '@/lib/cn'
import { useOverlayLayer } from './overlayLayer'
import { OverlayRootContext } from './overlayRoot'

interface DrawerProps {
  open: boolean
  onClose: () => void
  title?: ReactNode
  description?: ReactNode
  children: ReactNode
  footer?: ReactNode
  /** Panel width. */
  size?: 'sm' | 'md' | 'lg'
  /** Focus this element on open instead of the panel. */
  initialFocusRef?: RefObject<HTMLElement | null>
  /** Return focus here on close instead of the element that opened it. */
  returnFocusRef?: RefObject<HTMLElement | null>
}

/*
 * Widened a step in Phase 2.5 alongside the type scale: at 20px body and 60px
 * controls, a 32rem form panel forces every label onto its own line and every
 * value to wrap. The panel has to grow with the text it holds, or the extra
 * legibility is spent back on cramped layout.
 */
const sizes = {
  sm: 'sm:max-w-md',
  md: 'sm:max-w-xl',
  lg: 'sm:max-w-3xl',
}

/**
 * Right-anchored slide-over panel for forms and side detail. Full-width on
 * mobile, a fixed panel on ≥sm.
 *
 * Phase 2.10 (R2): the same overlay behaviour as {@link Modal}, through the
 * shared `overlayLayer` — it used to have its own Escape handler outside the
 * modal stack (so cancelling a confirmation raised from a drawer closed the
 * drawer too), took no focus on open and returned none on close. Now focus
 * moves in and is kept in, the page behind is inert, and Escape closes only
 * the topmost overlay.
 */
export function Drawer({
  open,
  onClose,
  title,
  description,
  children,
  footer,
  size = 'md',
  initialFocusRef,
  returnFocusRef,
}: DrawerProps) {
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

  return createPortal(
    <div ref={attachRoot} className="fixed inset-0 z-50 flex justify-end">
      <div
        className="absolute inset-0 bg-black/50 animate-fade-in"
        onClick={onClose}
        aria-hidden="true"
      />
      <div
        ref={dialogRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={title ? titleId : undefined}
        aria-describedby={description ? descId : undefined}
        tabIndex={-1}
        className={cn(
          'relative flex h-full w-full flex-col border-l border-border bg-surface shadow-[var(--shadow-overlay-md)] outline-none animate-sheet-in focus-visible:outline-none',
          sizes[size],
        )}
      >
        <OverlayRootContext value={overlayRoot}>
          <div className="flex items-start justify-between gap-4 border-b border-border px-5 py-3.5">
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
            <button
              type="button"
              onClick={onClose}
              aria-label="Close panel"
              className="-mr-1.5 -mt-0.5 rounded-sm p-1.5 text-muted hover:bg-surface-sunken hover:text-ink"
            >
              <X size={18} aria-hidden="true" />
            </button>
          </div>

          <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">{children}</div>

          {footer && (
            <div className="flex flex-wrap justify-end gap-2 border-t border-border px-5 py-3.5">
              {footer}
            </div>
          )}
        </OverlayRootContext>
      </div>
    </div>,
    document.body,
  )
}

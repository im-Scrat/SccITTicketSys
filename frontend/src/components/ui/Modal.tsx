import { X } from 'lucide-react'
import { type ReactNode, useEffect, useId } from 'react'
import { createPortal } from 'react-dom'
import { cn } from '@/lib/cn'

interface ModalProps {
  open: boolean
  onClose: () => void
  title?: ReactNode
  description?: ReactNode
  children: ReactNode
  footer?: ReactNode
  size?: 'sm' | 'md' | 'lg'
  /** Hide the header × control (e.g. a forced confirmation). */
  hideClose?: boolean
}

const sizes = {
  sm: 'max-w-sm',
  md: 'max-w-lg',
  lg: 'max-w-2xl',
}

/**
 * Centered modal dialog — a floating overlay, so it earns a shadow (Flat-Plane
 * Rule). Closes on Escape or backdrop click, locks body scroll while open, and
 * wires role="dialog" + aria-modal with a labelled title. The scrim is a fixed
 * dark wash that reads correctly in both themes.
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
}: ModalProps) {
  const titleId = useId()
  const descId = useId()

  useEffect(() => {
    if (!open) return
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.removeEventListener('keydown', onKey)
      document.body.style.overflow = previousOverflow
    }
  }, [open, onClose])

  if (!open) return null

  return createPortal(
    <div className="fixed inset-0 z-50 flex items-end justify-center p-0 sm:items-center sm:p-4">
      <div
        className="absolute inset-0 bg-black/50 animate-fade-in"
        onClick={onClose}
        aria-hidden="true"
      />
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby={title ? titleId : undefined}
        aria-describedby={description ? descId : undefined}
        className={cn(
          'relative w-full rounded-t-lg border border-border bg-surface shadow-[var(--shadow-overlay-md)] animate-sheet-in sm:rounded-lg',
          sizes[size],
        )}
      >
        {(title || !hideClose) && (
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
            {!hideClose && (
              <button
                type="button"
                onClick={onClose}
                aria-label="Close dialog"
                className="-mr-1.5 -mt-0.5 rounded-sm p-1.5 text-muted hover:bg-surface-sunken hover:text-ink"
              >
                <X size={18} aria-hidden="true" />
              </button>
            )}
          </div>
        )}

        <div className="max-h-[70vh] overflow-y-auto px-5 py-4">{children}</div>

        {footer && (
          <div className="flex flex-wrap justify-end gap-2 border-t border-border px-5 py-3.5">
            {footer}
          </div>
        )}
      </div>
    </div>,
    document.body,
  )
}

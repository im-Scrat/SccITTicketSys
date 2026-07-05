import { X } from 'lucide-react'
import { type ReactNode, useEffect, useId } from 'react'
import { createPortal } from 'react-dom'
import { cn } from '@/lib/cn'

interface DrawerProps {
  open: boolean
  onClose: () => void
  title?: ReactNode
  description?: ReactNode
  children: ReactNode
  footer?: ReactNode
  /** Panel width. */
  size?: 'sm' | 'md' | 'lg'
}

const sizes = {
  sm: 'sm:max-w-sm',
  md: 'sm:max-w-md',
  lg: 'sm:max-w-lg',
}

/**
 * Right-anchored slide-over panel for forms and side detail. Full-width on
 * mobile, a fixed panel on ≥sm. Same overlay semantics as {@link Modal}
 * (Escape / backdrop close, scroll lock, dialog roles).
 */
export function Drawer({
  open,
  onClose,
  title,
  description,
  children,
  footer,
  size = 'md',
}: DrawerProps) {
  const titleId = useId()

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
    <div className="fixed inset-0 z-50 flex justify-end">
      <div
        className="absolute inset-0 bg-black/50 animate-fade-in"
        onClick={onClose}
        aria-hidden="true"
      />
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby={title ? titleId : undefined}
        className={cn(
          'relative flex h-full w-full flex-col border-l border-border bg-surface shadow-[var(--shadow-overlay-md)] animate-sheet-in',
          sizes[size],
        )}
      >
        <div className="flex items-start justify-between gap-4 border-b border-border px-5 py-3.5">
          <div className="min-w-0">
            {title && (
              <h2 id={titleId} className="text-base font-semibold text-ink-strong">
                {title}
              </h2>
            )}
            {description && <p className="mt-0.5 text-sm text-muted">{description}</p>}
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
      </div>
    </div>,
    document.body,
  )
}

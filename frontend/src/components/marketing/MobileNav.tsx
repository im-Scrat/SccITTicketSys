import { useEffect, useRef } from 'react'
import { X } from 'lucide-react'
import { ButtonLink } from '@/components/ui/Button'
import { Logo } from '@/components/ui/Logo'
import { ThemeSegmented } from '@/components/ui/ThemeToggle'
import { primaryNav } from './navigation'

interface MobileNavProps {
  open: boolean
  onClose: () => void
}

/**
 * Full-width navigation sheet for small screens. Accessible dialog: focus is
 * trapped, Escape and backdrop dismiss it, background scroll is locked, and
 * focus returns to the trigger on close.
 */
export function MobileNav({ open, onClose }: MobileNavProps) {
  const panelRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!open) return

    const previouslyFocused = document.activeElement as HTMLElement | null
    const panel = panelRef.current

    const focusables = () =>
      panel
        ? Array.from(
            panel.querySelectorAll<HTMLElement>(
              'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])',
            ),
          )
        : []

    focusables()[0]?.focus()

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        onClose()
        return
      }
      if (event.key !== 'Tab') return
      const items = focusables()
      if (items.length === 0) return
      const first = items[0]
      const last = items[items.length - 1]
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown)
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => {
      document.removeEventListener('keydown', onKeyDown)
      document.body.style.overflow = previousOverflow
      previouslyFocused?.focus?.()
    }
  }, [open, onClose])

  if (!open) return null

  return (
    <div className="lg:hidden" role="dialog" aria-modal="true" aria-label="Site navigation">
      <div
        className="animate-fade-in fixed inset-0 z-[1200] bg-ink-strong/40"
        onClick={onClose}
        aria-hidden="true"
      />
      <div
        ref={panelRef}
        className="animate-sheet-in fixed inset-x-0 top-0 z-[1300] max-h-[100dvh] overflow-y-auto border-b border-border bg-surface p-4 shadow-[var(--shadow-overlay-md)]"
      >
        <div className="mb-2 flex items-center justify-between">
          <Logo size={26} />
          <button
            type="button"
            onClick={onClose}
            className="inline-flex size-9 items-center justify-center rounded-sm text-muted transition-colors duration-150 hover:bg-surface-sunken hover:text-ink"
            aria-label="Close navigation"
          >
            <X size={20} aria-hidden="true" />
          </button>
        </div>

        <nav className="flex flex-col py-2">
          {primaryNav.map((link) => (
            <a
              key={link.label}
              href={link.href}
              onClick={onClose}
              className="rounded-sm px-3 py-2.5 text-[0.9375rem] font-medium text-ink transition-colors duration-150 hover:bg-surface-sunken"
            >
              {link.label}
            </a>
          ))}
        </nav>

        <div className="mt-2 flex flex-col gap-2.5 border-t border-border pt-4">
          <ButtonLink to="/sign-in" variant="secondary" size="lg" onClick={onClose}>
            Sign in
          </ButtonLink>
          <ButtonLink href="#demo" variant="primary" size="lg" onClick={onClose}>
            Book a demo
          </ButtonLink>
        </div>

        <div className="mt-4 flex items-center justify-between border-t border-border pt-4">
          <span className="text-xs font-medium text-muted">Theme</span>
          <ThemeSegmented />
        </div>
      </div>
    </div>
  )
}

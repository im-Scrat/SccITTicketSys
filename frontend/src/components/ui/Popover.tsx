import { ChevronDown } from 'lucide-react'
import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { cn } from '@/lib/cn'

interface PopoverProps {
  /** What the button shows (the current value, e.g. "Open, Assigned"). */
  value: ReactNode
  /** The control's name, shown above it and used as the panel's label. */
  label: string
  children: ReactNode | ((close: () => void) => ReactNode)
  align?: 'start' | 'end'
  className?: string
}

/**
 * A labelled button that opens a small non-modal panel beneath it — for a
 * control too rich for a native select (several statuses at once).
 *
 * Non-modal on purpose: focus moves into the panel, Escape or a click outside
 * closes it and returns focus to the button, and the page behind stays usable.
 * It is placed in normal flow (`absolute` within a relative wrapper), so it
 * must not sit inside an `overflow: hidden` ancestor; the report toolbar has
 * none. Styled like the native selects beside it, so the toolbar reads as one
 * row of the same kind of control.
 */
export function Popover({ value, label, children, align = 'start', className }: PopoverProps) {
  const [open, setOpen] = useState(false)
  const id = useId()
  const wrapper = useRef<HTMLDivElement>(null)
  const button = useRef<HTMLButtonElement>(null)
  const panel = useRef<HTMLDivElement>(null)

  const close = (returnFocus = true) => {
    setOpen(false)
    if (returnFocus) button.current?.focus()
  }

  useEffect(() => {
    if (!open) return

    // Move focus to the first control inside, so keyboard users land in the panel.
    panel.current?.querySelector<HTMLElement>('input, button, select, a[href]')?.focus()

    // Capture phase, so this runs before an enclosing Modal or Drawer hears the
    // key (theirs is a bubble-phase listener on the same document): stopping it
    // here means Escape closes the panel, not the dialog the panel sits in.
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        setOpen(false)
        button.current?.focus()
      }
    }
    const onPointer = (event: PointerEvent) => {
      if (!wrapper.current?.contains(event.target as Node)) setOpen(false)
    }

    document.addEventListener('keydown', onKey, true)
    document.addEventListener('pointerdown', onPointer)
    return () => {
      document.removeEventListener('keydown', onKey, true)
      document.removeEventListener('pointerdown', onPointer)
    }
  }, [open])

  return (
    <div ref={wrapper} className={cn('relative flex flex-col gap-2', className)}>
      <span id={`${id}-label`} className="text-sm font-semibold text-ink">
        {label}
      </span>
      <button
        ref={button}
        type="button"
        aria-expanded={open}
        aria-controls={`${id}-panel`}
        aria-labelledby={`${id}-label ${id}-value`}
        onClick={() => setOpen((current) => !current)}
        className={cn(
          'flex h-15 w-full items-center justify-between gap-3 rounded-md border-2 bg-surface px-4 text-left text-sm font-medium text-ink',
          'transition-colors duration-150 [transition-timing-function:var(--ease-standard)]',
          open ? 'border-primary' : 'border-control-border',
        )}
      >
        <span id={`${id}-value`} className="min-w-0 truncate">
          {value}
        </span>
        <ChevronDown
          size={18}
          aria-hidden="true"
          className={cn('shrink-0 text-muted transition-transform', open && 'rotate-180')}
        />
      </button>
      {open && (
        <div
          ref={panel}
          id={`${id}-panel`}
          role="group"
          aria-labelledby={`${id}-label`}
          className={cn(
            'absolute top-full z-30 mt-2 w-max max-w-[min(22rem,calc(100vw-2rem))] min-w-full rounded-md border-2 border-border bg-surface p-3 shadow-md',
            align === 'end' ? 'right-0' : 'left-0',
          )}
        >
          {typeof children === 'function' ? children(() => close()) : children}
        </div>
      )}
    </div>
  )
}

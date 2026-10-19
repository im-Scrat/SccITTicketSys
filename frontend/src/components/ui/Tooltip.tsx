import {
  cloneElement,
  type FocusEvent,
  type PointerEvent,
  type ReactElement,
  type ReactNode,
  type Ref,
  type RefObject,
  useCallback,
  useEffect,
  useId,
  useRef,
  useState,
} from 'react'
import { createPortal } from 'react-dom'
import { useOverlayPosition } from '@/hooks/useOverlayPosition'
import { useOverlayRoot } from './overlayRoot'

interface TriggerProps {
  ref?: Ref<HTMLElement>
  'aria-describedby'?: string
  onPointerEnter?: (event: PointerEvent<HTMLElement>) => void
  onPointerLeave?: (event: PointerEvent<HTMLElement>) => void
  onFocus?: (event: FocusEvent<HTMLElement>) => void
  onBlur?: (event: FocusEvent<HTMLElement>) => void
}

interface TooltipProps {
  content: ReactNode
  /** One focusable element (a button, a link, a tabIndex=0 span). */
  children: ReactElement<TriggerProps>
  placement?: 'top' | 'bottom'
  /**
   * Link the text to the trigger as its accessible description (default).
   * Off when the trigger already contains the same words — TruncatedText's
   * full text is in the DOM, so a description would read it twice.
   */
  describe?: boolean
  disabled?: boolean
}

/** Hover intent: a pointer passing over a table should not flash tooltips. */
const OPEN_DELAY = 300
/** Long enough to move the pointer from the trigger onto the tooltip. */
const CLOSE_DELAY = 120

function assignRef<T>(ref: Ref<T> | undefined, value: T | null) {
  if (typeof ref === 'function') ref(value)
  else if (ref) (ref as RefObject<T | null>).current = value
}

/**
 * Supplementary text for a control, shown on hover and on keyboard focus.
 *
 * Never the only way to reach information: anything a tooltip says must also
 * be available another way (a visible label, the table twin of a chart, the
 * full text already in the DOM). It follows WCAG 1.4.13 — it stays while the
 * pointer moves onto it (hoverable), until the pointer or focus leaves
 * (persistent), and Escape hides it without moving focus or closing the
 * dialog it sits in (dismissible).
 *
 * The visible bubble is portalled (so a scrolling table cannot clip it) and
 * positioned through the CSSOM; screen readers get the text from a hidden
 * element beside the trigger, which exists before focus arrives.
 */
export function Tooltip({
  content,
  children,
  placement = 'top',
  describe = true,
  disabled = false,
}: TooltipProps) {
  const id = useId()
  const [open, setOpen] = useState(false)
  const triggerRef = useRef<HTMLElement | null>(null)
  const tipRef = useRef<HTMLDivElement>(null)
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)
  const portalTarget = useOverlayRoot()

  const clear = () => clearTimeout(timer.current)
  const show = (delay: number) => {
    clear()
    timer.current = setTimeout(() => setOpen(true), delay)
  }
  const hide = (delay = CLOSE_DELAY) => {
    clear()
    timer.current = setTimeout(() => setOpen(false), delay)
  }

  useEffect(() => () => clearTimeout(timer.current), [])

  useOverlayPosition(triggerRef, tipRef, open, { placement, align: 'center' })

  useEffect(() => {
    if (!open) return
    // Capture phase, ahead of an enclosing dialog's Escape (see Popover).
    const onKey = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return
      event.stopPropagation()
      clearTimeout(timer.current)
      setOpen(false)
    }
    document.addEventListener('keydown', onKey, true)
    return () => document.removeEventListener('keydown', onKey, true)
  }, [open])

  const childRef = children.props.ref
  const mergedRef = useCallback(
    (element: HTMLElement | null) => {
      triggerRef.current = element
      assignRef(childRef, element)
    },
    [childRef],
  )

  const { onPointerEnter, onPointerLeave, onFocus, onBlur } = children.props
  const describedBy =
    [children.props['aria-describedby'], describe && !disabled ? `${id}-text` : null]
      .filter(Boolean)
      .join(' ') || undefined

  // `disabled` hides an open tooltip by not rendering it; `open` is left as is.
  const trigger = cloneElement(children, {
    ref: mergedRef,
    'aria-describedby': describedBy,
    onPointerEnter: (event: PointerEvent<HTMLElement>) => {
      onPointerEnter?.(event)
      // A tap is not hover intent; on touch the tooltip would stick open.
      if (!disabled && event.pointerType !== 'touch') show(OPEN_DELAY)
    },
    onPointerLeave: (event: PointerEvent<HTMLElement>) => {
      onPointerLeave?.(event)
      hide()
    },
    onFocus: (event: FocusEvent<HTMLElement>) => {
      onFocus?.(event)
      if (!disabled) show(0)
    },
    onBlur: (event: FocusEvent<HTMLElement>) => {
      onBlur?.(event)
      hide(0)
    },
  })

  return (
    <>
      {trigger}
      {describe && !disabled && (
        <span id={`${id}-text`} hidden>
          {content}
        </span>
      )}
      {open &&
        !disabled &&
        createPortal(
          <div
            ref={tipRef}
            role="tooltip"
            // The hidden twin above is what assistive technology reads.
            aria-hidden="true"
            onPointerEnter={clear}
            onPointerLeave={() => hide()}
            className="fixed left-0 top-0 z-70 max-w-[min(22rem,calc(100vw-1rem))] rounded-md border border-border bg-surface px-3 py-2 text-xs font-medium text-ink shadow-[var(--shadow-overlay-sm)] animate-fade-in"
          >
            {content}
          </div>,
          portalTarget,
        )}
    </>
  )
}

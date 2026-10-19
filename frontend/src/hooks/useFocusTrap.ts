import { type RefObject, useEffect } from 'react'

const FOCUSABLE = [
  'a[href]',
  'area[href]',
  'button:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  'summary',
  'iframe',
  '[contenteditable="true"]',
  '[tabindex]:not([tabindex="-1"])',
].join(',')

/** The Tab stops inside a container, in document order. */
export function focusableWithin(container: HTMLElement): HTMLElement[] {
  return Array.from(container.querySelectorAll<HTMLElement>(FOCUSABLE)).filter(
    // `hidden`/`inert` rather than a layout check: it is what removes an
    // element from the Tab order, and it behaves the same in tests.
    (element) => !element.closest('[hidden], [inert]'),
  )
}

/**
 * Keeps Tab and Shift+Tab inside an open dialog, wrapping at either end
 * (WAI-ARIA dialog pattern). Paired with `useInertBackground`, which makes the
 * page behind unreachable; this hook handles the wrap, which inert alone does
 * not — without it Tab from the last control leaves for the browser chrome.
 */
export function useFocusTrap(containerRef: RefObject<HTMLElement | null>, active: boolean) {
  useEffect(() => {
    const container = containerRef.current
    if (!active || !container) return

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key !== 'Tab') return

      const stops = focusableWithin(container)
      if (stops.length === 0) {
        event.preventDefault()
        container.focus()
        return
      }

      const first = stops[0]
      const last = stops[stops.length - 1]
      const current = document.activeElement

      if (event.shiftKey && (current === first || current === container)) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && current === last) {
        event.preventDefault()
        first.focus()
      }
    }

    container.addEventListener('keydown', onKeyDown)
    return () => container.removeEventListener('keydown', onKeyDown)
  }, [containerRef, active])
}

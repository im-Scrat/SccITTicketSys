import { type RefObject, useEffect } from 'react'

/**
 * How many open overlays currently need each element inert. Counted, not
 * flagged: with a confirmation open over a drawer, closing the drawer first
 * must not wake the page while the confirmation is still up.
 */
const inertCounts = new Map<Element, number>()

/**
 * Makes everything outside an open overlay `inert` — unreachable by Tab, by
 * pointer and by assistive technology — while it is open (WCAG 2.4.3, and the
 * `aria-modal` promise kept for screen readers that ignore it).
 *
 * The overlay is portalled straight into `<body>`, so its siblings are the app
 * and any overlay beneath it. Elements that were already inert for another
 * reason are left alone and never woken by this hook.
 */
export function useInertBackground(rootRef: RefObject<HTMLElement | null>, active: boolean) {
  useEffect(() => {
    const root = rootRef.current
    if (!active || !root) return

    const touched: Element[] = []

    for (const sibling of Array.from(document.body.children)) {
      if (sibling === root || sibling.contains(root)) continue

      const count = inertCounts.get(sibling) ?? 0
      if (count === 0 && sibling.hasAttribute('inert')) continue // someone else's

      inertCounts.set(sibling, count + 1)
      if (count === 0) sibling.setAttribute('inert', '')
      touched.push(sibling)
    }

    return () => {
      for (const sibling of touched) {
        const count = (inertCounts.get(sibling) ?? 1) - 1
        if (count <= 0) {
          inertCounts.delete(sibling)
          sibling.removeAttribute('inert')
        } else {
          inertCounts.set(sibling, count)
        }
      }
    }
  }, [rootRef, active])
}

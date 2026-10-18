import { useEffect, useRef, useState } from 'react'

/**
 * Whether an element's text is currently cut off — wider than its box
 * (`truncate`) or taller (`line-clamp-*`). Re-checked whenever the element
 * resizes, so a column that widens stops offering a tooltip it no longer needs.
 */
export function useIsTruncated<T extends HTMLElement = HTMLSpanElement>(text: string) {
  const ref = useRef<T | null>(null)
  const [truncated, setTruncated] = useState(false)

  useEffect(() => {
    const element = ref.current
    if (!element) return

    const measure = () => {
      const next =
        element.scrollWidth > element.clientWidth + 1 ||
        element.scrollHeight > element.clientHeight + 1
      setTruncated((current) => (current === next ? current : next))
    }

    measure()
    if (typeof ResizeObserver === 'undefined') return
    const observer = new ResizeObserver(measure)
    observer.observe(element)
    return () => observer.disconnect()
  }, [text])

  return { ref, truncated }
}

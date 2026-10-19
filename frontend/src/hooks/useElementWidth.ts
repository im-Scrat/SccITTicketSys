import { useEffect, useRef, useState } from 'react'

/**
 * The live content-box width of an element, from a ResizeObserver.
 *
 * Charts size from their own container, not the viewport: the same chart sits
 * in a seven-column panel on a wide screen and full width on a phone. Width is
 * 0 until the first measurement, so callers render nothing rather than drawing
 * at a guessed size and jumping.
 */
export function useElementWidth<T extends HTMLElement = HTMLDivElement>() {
  const ref = useRef<T | null>(null)
  const [width, setWidth] = useState(0)

  useEffect(() => {
    const element = ref.current
    if (!element) return

    // jsdom and very old browsers have no ResizeObserver: measure once.
    if (typeof ResizeObserver === 'undefined') {
      setWidth(element.clientWidth)
      return
    }

    const observer = new ResizeObserver((entries) => {
      const next = Math.floor(entries[0]?.contentRect.width ?? 0)
      setWidth((current) => (current === next ? current : next))
    })
    observer.observe(element)
    return () => observer.disconnect()
  }, [])

  return { ref, width }
}

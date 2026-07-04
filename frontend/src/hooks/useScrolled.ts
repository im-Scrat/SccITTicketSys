import { useEffect, useState } from 'react'

/**
 * True once the window has scrolled past `threshold` px. Used to give the
 * sticky header a hairline separator only when content sits beneath it.
 */
export function useScrolled(threshold = 4): boolean {
  const [scrolled, setScrolled] = useState(false)

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > threshold)
    onScroll()
    window.addEventListener('scroll', onScroll, { passive: true })
    return () => window.removeEventListener('scroll', onScroll)
  }, [threshold])

  return scrolled
}

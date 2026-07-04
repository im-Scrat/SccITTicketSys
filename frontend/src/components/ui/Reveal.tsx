import { useLayoutEffect, useRef, type CSSProperties, type ReactNode } from 'react'
import { cn } from '@/lib/cn'

interface RevealProps {
  children: ReactNode
  className?: string
  style?: CSSProperties
  /** Stagger offset in milliseconds for lists. */
  delay?: number
}

/**
 * Scroll-reveal wrapper. Content renders fully visible by default; only when JS
 * is running *and* motion is allowed do we opt the element into the hidden→shown
 * transition (set synchronously in useLayoutEffect, before paint, so there's no
 * flash). Headless/no-JS renders never run the effect, so content is never
 * gated behind a transition that might not fire.
 */
export function Reveal({ children, className, style, delay = 0 }: RevealProps) {
  const ref = useRef<HTMLDivElement>(null)

  useLayoutEffect(() => {
    const el = ref.current
    if (!el) return

    const prefersReduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
    if (prefersReduced || typeof IntersectionObserver === 'undefined') return

    el.setAttribute('data-reveal', '')
    if (delay) el.style.transitionDelay = `${delay}ms`

    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            el.setAttribute('data-reveal', 'shown')
            observer.disconnect()
            break
          }
        }
      },
      { threshold: 0.12, rootMargin: '0px 0px -8% 0px' },
    )

    observer.observe(el)
    return () => observer.disconnect()
  }, [delay])

  return (
    <div ref={ref} className={cn(className)} style={style}>
      {children}
    </div>
  )
}

import { ArrowLeft } from 'lucide-react'
import { useEffect, useRef } from 'react'
import { useLocation } from 'react-router-dom'
import { ButtonLink, LiveRegion } from '@/components/ui'

interface ImportReturn {
  id: string
  file: string
  slide: string
}

function importReturnOf(state: unknown): ImportReturn | null {
  const value = (state as { importReturn?: unknown } | null)?.importReturn
  return value && typeof value === 'object' && typeof (value as ImportReturn).id === 'string'
    ? (value as ImportReturn)
    : null
}

/**
 * "Back to import review" (Phase 2.10 R7): shown above a page opened from the
 * review's "View this page", and only then. On arrival focus moves to the
 * page's heading, announced, so a keyboard or screen-reader user knows where
 * they are; the link returns to the same slide.
 */
export function ImportReturnBanner() {
  const location = useLocation()
  const origin = importReturnOf(location.state)
  const focused = useRef<string | null>(null)

  useEffect(() => {
    if (!origin || focused.current === location.key) return
    focused.current = location.key
    const timer = window.setTimeout(() => {
      const heading = document.querySelector<HTMLElement>('main h1')
      if (heading) {
        heading.tabIndex = -1
        heading.focus()
      }
    }, 300)
    return () => window.clearTimeout(timer)
  }, [origin, location.key])

  if (!origin) return null

  return (
    <div
      className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-md border border-border bg-surface-sunken px-4 py-3"
      data-print-hide
    >
      <LiveRegion message="Opened from the import review." />
      <p className="text-sm text-ink">
        Opened from the review of <span className="font-semibold">{origin.file}</span>.
      </p>
      <ButtonLink
        to={`/app/assets/imports/${origin.id}?review=1&slide=${encodeURIComponent(origin.slide)}`}
        variant="secondary"
        size="sm"
        leftIcon={<ArrowLeft size={18} aria-hidden="true" />}
      >
        Back to import review
      </ButtonLink>
    </div>
  )
}

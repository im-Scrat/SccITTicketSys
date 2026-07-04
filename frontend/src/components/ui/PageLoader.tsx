import { Spinner } from './Spinner'
import { Logo } from './Logo'

/**
 * Route-level Suspense fallback shown while a lazily-loaded page chunk resolves.
 * Branded and quiet — a spinner under the mark, with an accessible live region.
 */
export function PageLoader({ label = 'Loading' }: { label?: string }) {
  return (
    <div
      className="flex min-h-[60vh] flex-col items-center justify-center gap-5 py-24"
      role="status"
      aria-live="polite"
    >
      <Logo withWordmark={false} size={32} />
      <Spinner size={22} className="text-primary" />
      <span className="sr-only">{label}</span>
    </div>
  )
}

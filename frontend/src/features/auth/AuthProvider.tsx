import type { ReactNode } from 'react'
import { useCurrentUser } from './hooks/useAuth'

/**
 * Warms the current-principal query on app mount (automatic session
 * restoration) without blocking render — the public marketing site keeps its
 * instant paint, while the route guards handle their own loading state via
 * `isReady`.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  useCurrentUser()

  return <>{children}</>
}

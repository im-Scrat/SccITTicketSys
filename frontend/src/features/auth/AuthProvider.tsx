import { useEffect, type ReactNode } from 'react'
import { initTabSession } from '@/services/tabSession'
import { useCurrentUser } from './hooks/useAuth'

/**
 * Warms the current-principal query on app mount (automatic session
 * restoration) without blocking render — the public marketing site keeps its
 * instant paint, while the route guards handle their own loading state via
 * `isReady`.
 *
 * Also starts the per-tab lease's heartbeat/close-beacon wiring
 * (services/tabSession.ts) once for the app's lifetime — unconditionally,
 * not gated on being signed in, since the public site and an eventual sign-in
 * share this same mount and the heartbeat itself is a no-op without a lease.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  useCurrentUser()

  useEffect(() => {
    initTabSession()
  }, [])

  return <>{children}</>
}

import { Navigate, Outlet } from 'react-router-dom'
import { PageLoader } from '@/components/ui/PageLoader'
import { postAuthDestination } from '../lib/postAuthDestination'
import { useAuth } from '../hooks/useAuth'

/**
 * Gate for guest-only pages (sign-in, register, password reset).
 *
 * ── This guard decides where an authenticated visitor goes ────────────────
 *
 * Once the auth state flips, this is what renders, so it is the one place the
 * post-sign-in destination is decided — `SignInPage` never navigates on
 * success, and nothing that ran earlier can overwrite it. The destination is
 * the visitor's own dashboard, every time (owner decision 2026-10-19; see
 * {@link postAuthDestination}). A label scanned before signing in is offered
 * on the dashboard instead of being followed automatically.
 *
 * Resolving the destination has no side effect any more (it used to consume
 * the stored scan), so it is computed in render rather than latched in an
 * effect.
 */
export function GuestRoute() {
  const { isAuthenticated, isReady } = useAuth()

  if (!isReady) {
    return <PageLoader />
  }

  if (isAuthenticated) {
    return <Navigate to={postAuthDestination()} replace />
  }

  return <Outlet />
}

import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { PageLoader } from '@/components/ui/PageLoader'
import { useAuth } from '../hooks/useAuth'
import { DEFAULT_DESTINATION } from '../lib/postAuthDestination'

export const FORCED_PASSWORD_PATH = '/change-password'

/**
 * Gate for the authenticated application. Unauthenticated visitors are sent to
 * sign-in; once signed in they land on their dashboard, not back on the page
 * they tried (owner decision 2026-10-19), so no location is carried across.
 *
 * An account under an administrator's forced password reset is sent to the
 * change screen from everywhere else. Without this the server's
 * `password.current` middleware refuses every feature request with 403
 * `password_reset_required` while the shell renders anyway — each page then
 * shows its own "could not be loaded" error and nothing tells the user the one
 * thing that would fix all of them. This is navigation only; the middleware is
 * still the enforcement.
 */
export function ProtectedRoute() {
  const { user, isAuthenticated, isReady } = useAuth()
  const location = useLocation()

  if (!isReady) {
    return <PageLoader />
  }

  if (!isAuthenticated) {
    return <Navigate to="/sign-in" replace />
  }

  const onForcedScreen = location.pathname === FORCED_PASSWORD_PATH

  if (user?.force_password_reset && !onForcedScreen) {
    return <Navigate to={FORCED_PASSWORD_PATH} replace />
  }

  // Leaving the change screen is decided here alone — not also by the page —
  // and, like sign-in, it lands on the dashboard (owner decision 2026-10-19).
  // A scan made before signing in is still offered there.
  if (!user?.force_password_reset && onForcedScreen) {
    return <Navigate to={DEFAULT_DESTINATION} replace />
  }

  return <Outlet />
}

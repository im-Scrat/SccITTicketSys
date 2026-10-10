import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { PageLoader } from '@/components/ui/PageLoader'
import { useAuth } from '../hooks/useAuth'

export const FORCED_PASSWORD_PATH = '/change-password'

/**
 * Gate for the authenticated application. Unauthenticated visitors are sent to
 * sign-in with the attempted location preserved for redirect-back after login.
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
    return <Navigate to="/sign-in" replace state={{ from: location }} />
  }

  const onForcedScreen = location.pathname === FORCED_PASSWORD_PATH

  if (user?.force_password_reset && !onForcedScreen) {
    return <Navigate to={FORCED_PASSWORD_PATH} replace state={{ from: location }} />
  }

  // Leaving the change screen is decided here alone — not also by the page —
  // so a completed change returns the user to what they originally asked for
  // instead of whichever of two competing navigations happened to run last.
  if (!user?.force_password_reset && onForcedScreen) {
    const from = (location.state as { from?: { pathname?: string } } | null)?.from?.pathname
    return <Navigate to={from?.startsWith('/app') ? from : '/app'} replace />
  }

  return <Outlet />
}

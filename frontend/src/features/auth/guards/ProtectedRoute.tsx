import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { PageLoader } from '@/components/ui/PageLoader'
import { useAuth } from '../hooks/useAuth'

/**
 * Gate for the authenticated application. Unauthenticated visitors are sent to
 * sign-in with the attempted location preserved for redirect-back after login.
 */
export function ProtectedRoute() {
  const { isAuthenticated, isReady } = useAuth()
  const location = useLocation()

  if (!isReady) {
    return <PageLoader />
  }

  if (!isAuthenticated) {
    return <Navigate to="/sign-in" replace state={{ from: location }} />
  }

  return <Outlet />
}

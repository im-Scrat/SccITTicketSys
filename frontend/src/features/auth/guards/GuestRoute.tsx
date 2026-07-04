import { Navigate, Outlet } from 'react-router-dom'
import { PageLoader } from '@/components/ui/PageLoader'
import { useAuth } from '../hooks/useAuth'

/**
 * Gate for guest-only pages (sign-in, register, password reset). An already
 * authenticated user is redirected into the app.
 */
export function GuestRoute() {
  const { isAuthenticated, isReady } = useAuth()

  if (!isReady) {
    return <PageLoader />
  }

  if (isAuthenticated) {
    return <Navigate to="/app" replace />
  }

  return <Outlet />
}

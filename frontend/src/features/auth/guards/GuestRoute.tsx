import { useEffect, useRef, useState } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { PageLoader } from '@/components/ui/PageLoader'
import { postAuthDestination } from '../lib/postAuthDestination'
import { useAuth } from '../hooks/useAuth'

/**
 * Gate for guest-only pages (sign-in, register, password reset).
 *
 * ── This guard decides where an authenticated visitor goes ────────────────
 *
 * It used to send everyone to `/app`, and `SignInPage` separately navigated to
 * the real destination. Both ran, and this one ran *last* — its `<Navigate>`
 * effect fired after the imperative call and overwrote it, so a technician who
 * scanned a label signed in and landed on the dashboard (SRS FR-QR-011), as did
 * anyone returning to a page that had asked them to authenticate.
 *
 * The fix is not to make the two agree; it is to have one of them decide. The
 * guard is the right one — it is what renders while the auth state flips, so
 * nothing it does can be overwritten by something that ran earlier. That is
 * also what the SDD prescribes: the guard "has to learn about the pending
 * scan". {@link postAuthDestination} is where it learns.
 *
 * ── Why the destination is resolved in an effect, once ────────────────────
 *
 * Resolving it *consumes* the stored scan — a scan is spent once, so a later
 * sign-in in the same tab cannot replay it. That is a side effect, so it
 * belongs in an effect rather than in render, and the ref latch makes it
 * happen exactly once even though StrictMode mounts effects twice. The same
 * latch pattern guards the scan POST in `ScanLandingPage`, and for the same
 * reason.
 *
 * While the destination is being resolved the loader shows rather than the
 * outlet: rendering the sign-in form to someone who is already authenticated,
 * for one frame, is a flash of the wrong screen.
 */
export function GuestRoute() {
  const { isAuthenticated, isReady } = useAuth()
  const location = useLocation()

  const [destination, setDestination] = useState<string | null>(null)
  const resolved = useRef(false)

  useEffect(() => {
    if (!isReady || !isAuthenticated || resolved.current) return

    resolved.current = true
    setDestination(postAuthDestination(location))
  }, [isReady, isAuthenticated, location])

  if (!isReady) {
    return <PageLoader />
  }

  if (isAuthenticated) {
    return destination === null ? <PageLoader /> : <Navigate to={destination} replace />
  }

  return <Outlet />
}

import type { ReactNode } from 'react'
import ForbiddenPage from '@/pages/ForbiddenPage'
import { useAuth } from '../hooks/useAuth'

/**
 * Renders children only when the user holds one of the given roles; otherwise
 * shows the Forbidden (403) page.
 *
 * **Prefer {@link RequirePermission}.** Gating on a permission keeps the
 * navigation and the API in step automatically, and an individual grant
 * (FR-USER-004) opens both together with no second rule to maintain. This guard
 * exists for the one case a permission cannot express: Ticket Management, where
 * `tickets.view` is deliberately held by *all three* roles and the separation is
 * made by row-level visibility instead (SDD DD-40). No permission distinguishes
 * the administrator's oversight surface there, so the route has to.
 *
 * UX gating only, exactly as with permissions: every endpoint behind these
 * routes independently checks `TicketPolicy::viewAdministrative`, so typing the
 * url gains nothing.
 */
export function RequireRole({ roles, children }: { roles: string[]; children: ReactNode }) {
  const { user } = useAuth()

  if (!user || !roles.includes(user.role.slug)) {
    return <ForbiddenPage />
  }

  return <>{children}</>
}

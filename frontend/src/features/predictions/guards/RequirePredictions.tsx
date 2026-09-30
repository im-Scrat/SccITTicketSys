import type { ReactNode } from 'react'
import { RequirePermission } from '@/features/auth/guards/RequirePermission'
import { RequireRole } from '@/features/auth/guards/RequireRole'

/**
 * Predictive-maintenance findings are Administrator-only (WP-M), so this
 * guard asks for the role **and** the permission — the same two-part rule
 * the backend's `PcPredictionAccess` applies (and for the same reason
 * `RequireFloorPlan` does: a permission alone is not enough, because a
 * permission can be granted to an individual and this surface must stay
 * closed to a Technician or Teacher who was granted one).
 *
 * Fails closed: with no signed-in user, or an unknown role, the Forbidden
 * page is shown. UX gating only — every endpoint behind these pages
 * independently refuses anyone but an Administrator, so typing the URL
 * gains nothing.
 */
export function RequirePredictions({ children }: { children: ReactNode }) {
  return (
    <RequireRole roles={['administrator']}>
      <RequirePermission permission="predictions.view">{children}</RequirePermission>
    </RequireRole>
  )
}

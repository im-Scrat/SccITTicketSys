import type { ReactNode } from 'react'
import { RequirePermission } from '@/features/auth/guards/RequirePermission'
import { RequireRole } from '@/features/auth/guards/RequireRole'

/**
 * The floor plan is Administrator-only, so this guard asks for the role **and**
 * the permission — the same two-part rule the backend's `FloorPlanAccess`
 * applies. A permission alone is not enough: permissions can be granted to an
 * individual, and the floor plan must stay closed to a Technician or Teacher
 * who was granted one.
 *
 * Fails closed: with no signed-in user, or an unknown role, the Forbidden page
 * is shown. UX gating only — every endpoint behind these pages independently
 * refuses anyone but an Administrator, so typing the URL gains nothing.
 */
export function RequireFloorPlan({ children }: { children: ReactNode }) {
  return (
    <RequireRole roles={['administrator']}>
      <RequirePermission permission="floorplan.view">{children}</RequirePermission>
    </RequireRole>
  )
}

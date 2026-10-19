import type { ReactNode } from 'react'
import { RequirePermission } from '@/features/auth/guards/RequirePermission'
import { RequireRole } from '@/features/auth/guards/RequireRole'

/**
 * Report surfaces are Administrator-only (owner decision D8): the role **and**
 * `reports.view` — the same two-part rule the backend's `ReportsAccess`
 * applies, because technicians hold `reports.view` and must still be refused.
 * Pass `export` for the print routes, which also need `reports.export`.
 *
 * UX gating only — every report endpoint refuses anyone else regardless.
 */
export function RequireReports({
  children,
  export: needsExport = false,
}: {
  children: ReactNode
  export?: boolean
}) {
  const inner = needsExport ? (
    <RequirePermission permission="reports.export">{children}</RequirePermission>
  ) : (
    children
  )

  return (
    <RequireRole roles={['administrator']}>
      <RequirePermission permission="reports.view">{inner}</RequirePermission>
    </RequireRole>
  )
}

import type { ReactNode } from 'react'
import { RequirePermission } from '@/features/auth/guards/RequirePermission'
import { RequireRole } from '@/features/auth/guards/RequireRole'

/**
 * The asset import is Administrator-only (Phase 2.10 R6): the role **and**
 * `assets.view` + `assets.create`, the same rule as the backend's
 * `AssetImportAccess` — a technician granted `assets.create` by hand is still
 * refused there, so they are refused here too.
 *
 * UX gating only — every import endpoint refuses anyone else regardless.
 */
export function RequireAssetImport({ children }: { children: ReactNode }) {
  return (
    <RequireRole roles={['administrator']}>
      <RequirePermission permission="assets.view">
        <RequirePermission permission="assets.create">{children}</RequirePermission>
      </RequirePermission>
    </RequireRole>
  )
}

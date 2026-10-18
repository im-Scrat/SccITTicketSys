import { useAuth } from '@/features/auth/hooks/useAuth'

/**
 * Who may see reports, as the server decides it (owner decision D8): the
 * Administrator role **and** `reports.view` — technicians hold that permission
 * and still may not. Export, email and print also need `reports.export`.
 *
 * UX only: every report endpoint refuses anyone else regardless.
 */
export function useReportsAccess(): { canView: boolean; canExport: boolean } {
  const { user, hasPermission } = useAuth()
  const canView =
    // `role?`: a session can briefly hold a user before its role is known.
    user?.role?.slug === 'administrator' &&
    hasPermission('reports.view') &&
    hasPermission('tickets.view')

  return { canView, canExport: canView && hasPermission('reports.export') }
}

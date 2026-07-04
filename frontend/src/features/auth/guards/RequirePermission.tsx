import type { ReactNode } from 'react'
import ForbiddenPage from '@/pages/ForbiddenPage'
import { useAuth } from '../hooks/useAuth'

/**
 * Renders children only when the user holds the given permission; otherwise
 * shows the Forbidden (403) page. UX gating only — the API re-checks every call.
 */
export function RequirePermission({
  permission,
  children,
}: {
  permission: string
  children: ReactNode
}) {
  const { hasPermission } = useAuth()

  if (!hasPermission(permission)) {
    return <ForbiddenPage />
  }

  return <>{children}</>
}

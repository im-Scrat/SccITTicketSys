import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { usePrivateChannel } from '@/hooks/usePrivateChannel'
import { dashboardKeys } from './queries'

export const DASHBOARD_CHANGED_EVENT = '.dashboard.changed'

export interface DashboardChange {
  kind: string
  subject: string | null
}

/**
 * Phase 2.9 — the administrators' live channel (`admin.dashboard`).
 *
 * A change elsewhere (a ticket reported, assigned, escalated; maintenance
 * completed; a support request raised…) arrives as `{kind, subject}` and the
 * affected queries are invalidated, so the dashboard updates without a
 * refresh. Only an Administrator subscribes — and the server refuses anyone
 * else regardless (AdminDashboardAccess).
 *
 * Returns whether the channel is live and the most recent change, so the page
 * can say honestly whether it is updating by itself.
 */
export function useAdminDashboardChannel(): { live: boolean; lastChange: DashboardChange | null } {
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const [live, setLive] = useState(false)
  const [lastChange, setLastChange] = useState<DashboardChange | null>(null)

  usePrivateChannel(
    user?.role.slug === 'administrator' ? 'admin.dashboard' : undefined,
    {
      [DASHBOARD_CHANGED_EVENT]: (payload: DashboardChange) => {
        setLastChange(payload)
        void queryClient.invalidateQueries({ queryKey: dashboardKeys.all })

        if (payload.kind.startsWith('ticket.')) {
          void queryClient.invalidateQueries({ queryKey: ['tickets', 'feed'] })
          void queryClient.invalidateQueries({ queryKey: ['tickets', 'directory'] })
          void queryClient.invalidateQueries({ queryKey: ['tickets', 'dashboard'] })
        }

        void queryClient.invalidateQueries({ queryKey: ['technicians'] })
      },
    },
    { onLiveChange: setLive },
  )

  return { live, lastChange }
}

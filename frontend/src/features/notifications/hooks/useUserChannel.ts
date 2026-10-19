import { useQueryClient } from '@tanstack/react-query'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { ticketsKeys } from '@/features/tickets/hooks/queries'
import { usePrivateChannel } from '@/hooks/usePrivateChannel'
import { useRealtimeStore } from '@/stores/useRealtimeStore'
import { notificationKeys } from './queries'

/** The framework's event name for a broadcast notification (Echo's `.notification()`). */
export const NOTIFICATION_EVENT = '.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated'
export const AI_ANALYSIS_READY_EVENT = '.ai-analysis.ready'

/**
 * Phase 2.9 — the signed-in user's own private channel, `users.{uuid}`.
 * Mounted once, in the app shell.
 *
 * Every payload on it is a signal, not content: a notification says only its
 * topic, and "AI analysis ready" only the ticket's uuid. So the handlers do one
 * thing — invalidate — and the data itself is refetched through the
 * owner-scoped HTTP endpoints, exactly as if the user had refreshed.
 */
export function useUserChannel(): void {
  const { user } = useAuth()
  const queryClient = useQueryClient()
  const setLive = useRealtimeStore((state) => state.setUserChannelLive)

  usePrivateChannel(
    user ? `users.${user.id}` : undefined,
    {
      [NOTIFICATION_EVENT]: () => {
        void queryClient.invalidateQueries({ queryKey: notificationKeys.all })
      },
      [AI_ANALYSIS_READY_EVENT]: (payload: { ticket?: string }) => {
        if (!payload.ticket) return
        void queryClient.invalidateQueries({ queryKey: ticketsKeys.aiAnalysis(payload.ticket) })
        void queryClient.invalidateQueries({ queryKey: ticketsKeys.detail(payload.ticket) })
      },
    },
    { onLiveChange: setLive },
  )
}

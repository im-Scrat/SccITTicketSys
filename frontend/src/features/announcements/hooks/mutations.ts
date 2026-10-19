import { useMutation, useQueryClient } from '@tanstack/react-query'
import { notificationKeys } from '@/features/notifications/hooks/queries'
import {
  createAnnouncement,
  deleteAnnouncement,
  notifyAgain,
  publishAnnouncement,
  unpublishAnnouncement,
  updateAnnouncement,
} from '../api/announcementsApi'
import type { AnnouncementInput } from '../types'
import { announcementKeys } from './queries'

/**
 * The announcement writes.
 *
 * Not optimistic, deliberately — unlike the notification read/unread pair. Each
 * of these can change what an entire audience sees, and two of them send
 * notifications; guessing the server's answer to that and rolling it back would
 * be a second, quieter model of a broadcast. The administrator is already
 * waiting on a confirmation here, so the round-trip is the honest thing to show.
 *
 * **Publishing invalidates the notification keys as well.** The publisher does
 * not receive their own announcement (WP-2.7c decision D9), so their badge will not
 * move — but any other administrator's will, and the reader list they can see
 * changes immediately. Refreshing both trees is cheaper than reasoning about
 * which one moved.
 */
function useInvalidate() {
  const queryClient = useQueryClient()

  return () => {
    void queryClient.invalidateQueries({ queryKey: announcementKeys.all })
    // The dashboard widget reads announcements too.
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
  }
}

function useInvalidateWithNotifications() {
  const queryClient = useQueryClient()
  const invalidate = useInvalidate()

  return () => {
    invalidate()
    void queryClient.invalidateQueries({ queryKey: notificationKeys.all })
  }
}

export function useCreateAnnouncement() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: (payload: AnnouncementInput) => createAnnouncement(payload),
    onSuccess: invalidate,
  })
}

export function useUpdateAnnouncement() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: ({ id, ...payload }: AnnouncementInput & { id: string }) =>
      updateAnnouncement(id, payload),
    // Editing notifies nobody (WP-2.7c D7), so the notification tree is left alone.
    onSuccess: invalidate,
  })
}

export function usePublishAnnouncement() {
  const invalidate = useInvalidateWithNotifications()

  return useMutation({
    mutationFn: (id: string) => publishAnnouncement(id),
    onSuccess: invalidate,
  })
}

export function useUnpublishAnnouncement() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: (id: string) => unpublishAnnouncement(id),
    onSuccess: invalidate,
  })
}

/** The deliberate repeat (WP-2.7c decision D7). */
export function useNotifyAgain() {
  const invalidate = useInvalidateWithNotifications()

  return useMutation({
    mutationFn: (id: string) => notifyAgain(id),
    onSuccess: invalidate,
  })
}

export function useDeleteAnnouncement() {
  const invalidate = useInvalidate()

  return useMutation({
    mutationFn: (id: string) => deleteAnnouncement(id),
    onSuccess: invalidate,
  })
}

import { api } from '@/services/api'
import type { Announcement, AnnouncementFilters, AnnouncementInput, Paginated } from '../types'

/**
 * The announcement API (SRS FR-NOT-010/011).
 *
 * Split into a **reader** and a **management** half, mirroring the server: they
 * are different routes with different scopes, not one endpoint with a
 * parameter, so a client cannot cross from one to the other by changing a
 * query string.
 *
 * **Publication is never a field.** There is no `is_active` in any payload
 * below; publishing, withdrawing and re-notifying are named calls. That is what
 * stops an ordinary edit from notifying an audience as a side effect
 * (WP-2.7c decision D7).
 */

/* ------------------------------------------------------------- reader */

/** Announcements addressed to the caller, pinned first (FR-NOT-011). */
export async function fetchAnnouncements(page = 1): Promise<Paginated<Announcement>> {
  const { data } = await api.get<Paginated<Announcement>>('/announcements', {
    params: page > 1 ? { page } : undefined,
  })

  return data
}

/** One announcement — 403 if the caller is outside its audience. */
export async function fetchAnnouncement(id: string): Promise<Announcement> {
  const { data } = await api.get<{ data: Announcement }>(`/announcements/${id}`)

  return data.data
}

/* --------------------------------------------------------- management */

export async function fetchManagedAnnouncements(
  filters: AnnouncementFilters = {},
): Promise<Paginated<Announcement>> {
  const { data } = await api.get<Paginated<Announcement>>('/admin/announcements', {
    params: {
      ...(filters.audience ? { audience: filters.audience } : {}),
      ...(filters.active === null || filters.active === undefined
        ? {}
        : { active: filters.active ? 1 : 0 }),
      ...(filters.page && filters.page > 1 ? { page: filters.page } : {}),
    },
  })

  return data
}

export async function createAnnouncement(payload: AnnouncementInput): Promise<Announcement> {
  const { data } = await api.post<{ data: Announcement }>('/admin/announcements', payload)

  return data.data
}

export async function updateAnnouncement(
  id: string,
  payload: AnnouncementInput,
): Promise<Announcement> {
  const { data } = await api.put<{ data: Announcement }>(`/admin/announcements/${id}`, payload)

  return data.data
}

/** Make it live and notify its audience (FR-NOT-003 trigger 12). */
export async function publishAnnouncement(id: string): Promise<Announcement> {
  const { data } = await api.post<{ data: Announcement }>(`/admin/announcements/${id}/publish`)

  return data.data
}

/** Withdraw it. Notifies nobody. */
export async function unpublishAnnouncement(id: string): Promise<Announcement> {
  const { data } = await api.post<{ data: Announcement }>(`/admin/announcements/${id}/unpublish`)

  return data.data
}

/**
 * Tell the audience again, deliberately (WP-2.7c decision D7).
 *
 * Its own call because it is its own decision: editing never does this.
 */
export async function notifyAgain(id: string): Promise<Announcement> {
  const { data } = await api.post<{ data: Announcement }>(`/admin/announcements/${id}/notify`)

  return data.data
}

export async function deleteAnnouncement(id: string): Promise<void> {
  await api.delete(`/admin/announcements/${id}`)
}

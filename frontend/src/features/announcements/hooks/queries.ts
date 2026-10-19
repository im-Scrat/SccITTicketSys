import { keepPreviousData, useQuery } from '@tanstack/react-query'
import {
  fetchAnnouncement,
  fetchAnnouncements,
  fetchManagedAnnouncements,
} from '../api/announcementsApi'
import type { AnnouncementFilters } from '../types'

/**
 * Query keys for the announcement slice.
 *
 * The reader and the management list are **separate keys**, not one key with a
 * flag, because they answer different questions and are governed by different
 * rules: the reader is audience-scoped, management is not. Keeping them apart
 * means publishing an announcement can refresh both without either having to
 * know what the other is allowed to see.
 */
export const announcementKeys = {
  all: ['announcements'] as const,
  readable: (page = 1) => ['announcements', 'readable', page] as const,
  detail: (id: string) => ['announcements', 'detail', id] as const,
  managed: (filters: AnnouncementFilters = {}) =>
    [
      'announcements',
      'managed',
      filters.audience ?? 'any',
      filters.active ?? 'any',
      filters.page ?? 1,
    ] as const,
}

/** Announcements addressed to this reader (FR-NOT-011). */
export function useAnnouncements(page = 1) {
  return useQuery({
    queryKey: announcementKeys.readable(page),
    queryFn: () => fetchAnnouncements(page),
    placeholderData: keepPreviousData,
  })
}

/**
 * One announcement.
 *
 * A 403 here is a legitimate answer, not a fault: it is what a reader outside
 * the audience gets, and the page renders it as a refusal rather than retrying.
 */
export function useAnnouncement(id: string) {
  return useQuery({
    queryKey: announcementKeys.detail(id),
    queryFn: () => fetchAnnouncement(id),
    retry: false,
  })
}

/** The administrator's list: drafts, expired and every audience. */
export function useManagedAnnouncements(filters: AnnouncementFilters = {}) {
  return useQuery({
    queryKey: announcementKeys.managed(filters),
    queryFn: () => fetchManagedAnnouncements(filters),
    placeholderData: keepPreviousData,
  })
}

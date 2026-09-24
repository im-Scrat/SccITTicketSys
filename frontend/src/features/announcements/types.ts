/**
 * Client mirrors of the WP-2.7c announcement contract (SRS FR-NOT-010/011).
 *
 * Two projections of one entity, and the difference is an authorization
 * boundary rather than a display choice. A **reader** receives the announcement
 * itself; a **manager** additionally receives `is_active`, the author and the
 * update timestamp, because those are the fields the management surface edits.
 *
 * The management-only fields are therefore optional here — the reader payload
 * structurally does not contain them, and a component handed a reader
 * announcement cannot render a draft state that was never sent.
 */

export interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
  }
}

/** The four audiences the `announcements.audience` column allows. */
export type AnnouncementAudience = 'all' | 'teachers' | 'technicians' | 'admins'

export interface Announcement {
  /** The public uuid. The internal key is never published. */
  id: string
  title: string
  /** Plain text (WP-2.7c decision D4) — rendered as escaped text, never as HTML. */
  content: string
  audience: AnnouncementAudience
  is_pinned: boolean
  starts_at: string | null
  ends_at: string | null
  created_at: string | null

  /* Management-only. Absent from a reader's payload. */
  is_active?: boolean
  updated_at?: string | null
  created_by?: { id: string; name: string } | null
}

/** What the create/edit form sends. `is_active` is deliberately absent. */
export interface AnnouncementInput {
  title: string
  content: string
  audience: AnnouncementAudience
  starts_at?: string | null
  ends_at?: string | null
  is_pinned?: boolean
}

/** Filters the management list accepts. */
export interface AnnouncementFilters {
  audience?: AnnouncementAudience | null
  active?: boolean | null
  page?: number
}

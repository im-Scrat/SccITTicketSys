/**
 * Client mirrors of the WP-2.7a notification contract (SRS FR-NOT-001/002/004/005).
 *
 * Every field here exists on `NotificationResource`; nothing here is derived.
 * The three the resource deliberately withholds — the internal `id`, the
 * `user_id` and the `dedupe_key` — have no place in this file either, and a
 * client that grew a field for one of them would be describing a response the
 * server does not send.
 *
 * ── Why `type` and `topic` are plain strings ───────────────────────────────
 *
 * Both are backed by server-side enums that will grow: D5 has already been
 * accepted, so `announcement` notifications start arriving in a later work
 * package, and WP-2.4b adds procurement topics after that. A closed union here
 * would be a promise the wire cannot keep — the day a new case ships, TypeScript
 * would say the value is impossible while the browser renders it.
 *
 * There is deliberately no list of "the nine values known today" either. The
 * vocabulary a user sees comes from the API's own `meta.types`, so the filter
 * and the preference matrix widen on their own; a second list here would be a
 * copy of an enum this code cannot see, and the first thing to fall behind it.
 * Every lookup keyed on a type carries a default arm instead.
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

/** What the wire carries: whatever the server's enum says today. */
export type NotificationTypeValue = string

/** The trigger identity (`ticket.commented`, `account.locked`, …). */
export type NotificationTopicValue = string

export interface AppNotification {
  /** The public uuid. The internal key is never published. */
  id: string
  type: NotificationTypeValue
  /** Null for a notification written before topics existed, or by a future trigger. */
  topic: NotificationTopicValue | null
  title: string
  message: string | null
  /**
   * A free-form bag whose keys vary per topic. Read named keys only, and render
   * them as text — never spread it into the DOM.
   */
  payload: Record<string, unknown>
  /** Relative, server-built, and may be absent (`account.locked` has none). */
  action_url: string | null
  /** Authoritative. The client never re-derives read state from `read_at`. */
  is_read: boolean
  read_at: string | null
  created_at: string | null
}

/** Filters the list endpoint accepts. Anything else is filtered server-side or not at all. */
export interface NotificationFilters {
  unread?: boolean
  type?: NotificationTypeValue | null
  page?: number
}

/** One `{value, label}` pair from an API `meta` block. */
export interface Option {
  value: string
  label: string
}

export interface NotificationPreference {
  channel: string
  notification_type: NotificationTypeValue
  is_enabled: boolean
}

/**
 * The complete matrix plus the vocabulary to render it.
 *
 * `channels` and `types` come from the server so the grid widens on its own when
 * a channel or a type is added — the matrix is never a hard-coded grid.
 */
export interface NotificationPreferenceMatrix {
  data: NotificationPreference[]
  meta: {
    channels: Option[]
    types: Option[]
    /** Absent row means enabled. Stated by the API so the client never infers it. */
    default_enabled: boolean
  }
}

import { useCallback, useEffect, useRef, useState } from 'react'

/**
 * The unsent report, kept for the tab (SDD DD-80).
 *
 * **Why it exists.** On a low-memory Android phone the browser may discard the
 * tab while the camera app is in front; when the teacher comes back with the
 * photo, the page reloads and everything they had typed is gone — in front of a
 * class, which is exactly when this form is used. So the text fields and the
 * choices are saved as they are typed, and offered back on return.
 *
 * Decisions:
 *
 *  - **`sessionStorage`, never `localStorage`** (the `pendingScan` precedent):
 *    a half-written report belongs to this tab, and must not follow the next
 *    person on a shared staff-room machine. It is also keyed by user, the
 *    sign-out cleanup removes every draft ({@link clearReportDrafts}), and a
 *    sign-in removes everyone else's ({@link clearReportDraftsExcept}) — the
 *    session may have expired without a sign-out. Filing the report, or
 *    pressing Cancel, removes this user's draft too.
 *  - **Photos are not saved** — they are files, and storage is the wrong place
 *    for them. The restore note says so, so nobody assumes they came back.
 *  - **Twelve hours, then it is stale.** A draft from this morning's lesson is
 *    still useful at lunch; one from last week is not.
 *  - **Every read and write is guarded.** Private browsing, a full quota or a
 *    policy can make storage throw; losing a draft costs one retype, which is
 *    never worth breaking the form over.
 */

const PREFIX = 'sccit.reportDraft.'
const VERSION = 1
export const REPORT_DRAFT_TTL_MS = 12 * 60 * 60 * 1000
const SAVE_DELAY_MS = 400

export interface ReportDraftValues {
  title: string
  description: string
  category: string
  category_detail: string
  priority: string
  building: string
  room: string
  item: string
}

const FIELDS: Array<keyof ReportDraftValues> = [
  'title',
  'description',
  'category',
  'category_detail',
  'priority',
  'building',
  'room',
  'item',
]

/**
 * The fields that make a draft worth keeping. `building` is saved with the
 * rest but is not one of them: the form chooses it by itself when the site
 * has a single building, so a building on its own is not something the
 * reporter typed or chose — counting it would store an untouched form and
 * greet the next visit with a false "Restored your unsent report".
 */
const CONTENT_FIELDS = FIELDS.filter((field) => field !== 'building')

/** Longest value kept per field — the form's own limits, so a tampered entry cannot balloon. */
const MAX_LENGTH = 10_000

interface StoredDraft {
  v: number
  savedAt: number
  values: ReportDraftValues
}

function keyFor(userId: string): string {
  return `${PREFIX}${userId}`
}

function isEmpty(values: Partial<ReportDraftValues>): boolean {
  return CONTENT_FIELDS.every((field) => !(values[field] ?? '').trim())
}

/** The stored draft for this user, or `null` — missing, stale, or not written by this form. */
export function readReportDraft(userId: string, now = Date.now()): ReportDraftValues | null {
  let raw: string | null
  try {
    raw = sessionStorage.getItem(keyFor(userId))
  } catch {
    return null
  }
  if (!raw) return null

  try {
    const parsed = JSON.parse(raw) as Partial<StoredDraft>
    if (parsed?.v !== VERSION || typeof parsed.savedAt !== 'number') return null
    if (now - parsed.savedAt > REPORT_DRAFT_TTL_MS || parsed.savedAt > now + 60_000) {
      clearReportDraft(userId)
      return null
    }

    const values = {} as ReportDraftValues
    for (const field of FIELDS) {
      const value = (parsed.values as Record<string, unknown> | undefined)?.[field]
      values[field] = typeof value === 'string' ? value.slice(0, MAX_LENGTH) : ''
    }
    return isEmpty(values) ? null : values
  } catch {
    return null
  }
}

export function saveReportDraft(userId: string, values: ReportDraftValues, now = Date.now()): void {
  try {
    if (isEmpty(values)) {
      sessionStorage.removeItem(keyFor(userId))
      return
    }
    const stored: StoredDraft = { v: VERSION, savedAt: now, values }
    sessionStorage.setItem(keyFor(userId), JSON.stringify(stored))
  } catch {
    // Storage unavailable or full: the form works the same, only without a safety net.
  }
}

export function clearReportDraft(userId: string): void {
  try {
    sessionStorage.removeItem(keyFor(userId))
  } catch {
    // Nothing depends on it having worked.
  }
}

/** Remove every user's draft from this tab — part of signing out. */
export function clearReportDrafts(): void {
  removeDrafts(() => true)
}

/**
 * Remove every draft in this tab except `userId`'s — part of signing in.
 *
 * A session can end without a sign-out: it expires, the server answers 401,
 * and the next person signs in on the same tab. The previous person's
 * half-written report must not stay behind for them. The person signing in
 * keeps their own draft, because an expired session is exactly what this
 * draft exists to survive — which is also why the 401 itself clears nothing.
 */
export function clearReportDraftsExcept(userId: string): void {
  const keep = keyFor(userId)
  removeDrafts((key) => key !== keep)
}

function removeDrafts(shouldRemove: (key: string) => boolean): void {
  try {
    const keys: string[] = []
    for (let index = 0; index < sessionStorage.length; index += 1) {
      const key = sessionStorage.key(index)
      if (key?.startsWith(PREFIX) && shouldRemove(key)) keys.push(key)
    }
    for (const key of keys) sessionStorage.removeItem(key)
  } catch {
    // As above.
  }
}

/**
 * The report form's draft: what to start from, a debounced `save`, and
 * `clear` for when the report has been filed.
 *
 * `restored` is read once, when the form first renders, and stays put — it is
 * what the form's default values come from, and what the restore note is
 * about. After `clear()`, later `save` calls are ignored, so the form's last
 * change events cannot write the filed report back.
 */
export function useReportDraft(userId: string | null) {
  const [restored] = useState(() => (userId ? readReportDraft(userId) : null))
  const [noteVisible, setNoteVisible] = useState(restored !== null)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const finished = useRef(false)
  const pending = useRef<ReportDraftValues | null>(null)

  const flush = useCallback(() => {
    if (timer.current) clearTimeout(timer.current)
    timer.current = null
    if (!userId || finished.current || !pending.current) return
    saveReportDraft(userId, pending.current)
    pending.current = null
  }, [userId])

  const save = useCallback(
    (values: ReportDraftValues) => {
      if (!userId || finished.current) return
      pending.current = values
      if (timer.current) clearTimeout(timer.current)
      timer.current = setTimeout(flush, SAVE_DELAY_MS)
    },
    [userId, flush],
  )

  const clear = useCallback(() => {
    finished.current = true
    pending.current = null
    if (timer.current) clearTimeout(timer.current)
    timer.current = null
    setNoteVisible(false)
    if (userId) clearReportDraft(userId)
  }, [userId])

  // The tab being hidden — the camera app coming to the front — writes a
  // waiting save at once: the tab may be discarded before the timer fires, and
  // that last sentence is the one worth keeping. Unmounting does *not* write:
  // it also happens on signing out, which has just removed every draft.
  useEffect(() => {
    const onHide = () => {
      if (document.visibilityState === 'hidden') flush()
    }
    document.addEventListener('visibilitychange', onHide)
    window.addEventListener('pagehide', flush)
    return () => {
      document.removeEventListener('visibilitychange', onHide)
      window.removeEventListener('pagehide', flush)
      if (timer.current) clearTimeout(timer.current)
      timer.current = null
    }
  }, [flush])

  return {
    restored,
    /** Whether the "restored your unsent report" note is showing. */
    noteVisible,
    dismissNote: () => setNoteVisible(false),
    save,
    clear,
  }
}

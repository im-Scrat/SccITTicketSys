import type { LayoutDoc } from './layoutDocument'

/**
 * An unsaved Floorplan draft kept in this tab (Phase 2.11, SDD DD-101 D3) —
 * the same rules as the problem-report draft (DD-80):
 *
 *  - **sessionStorage, never localStorage.** A draft belongs to this tab and
 *    must not follow the next person on a shared machine.
 *  - **Keyed by user and room**, cleared on save and on discard, removed for
 *    everyone at sign-out and for everyone else at sign-in.
 *  - **It survives what the router cannot guard:** a refresh, the browser's
 *    Back button, a crashed tab restored by the browser.
 *  - **The base travels with the draft**, so a draft recovered after someone
 *    else saved can be merged object by object rather than overwritten.
 *  - **Every read and write is guarded.** Storage can throw (private mode,
 *    quota, policy); losing a draft is never worth breaking the editor over.
 */

const PREFIX = 'sccit.floorplanDraft.'
/** A draft older than this is not offered back. */
const MAX_AGE_MS = 12 * 60 * 60 * 1000

export interface StoredDraft {
  layoutVersion: number
  revision: number
  base: LayoutDoc
  draft: LayoutDoc
  savedAt: number
}

function keyFor(userId: string, roomId: string): string {
  return `${PREFIX}${userId}.${roomId}`
}

export function readDraft(userId: string, roomId: string): StoredDraft | null {
  try {
    const raw = window.sessionStorage.getItem(keyFor(userId, roomId))
    if (!raw) return null
    const parsed = JSON.parse(raw) as StoredDraft
    if (
      typeof parsed !== 'object' ||
      parsed === null ||
      typeof parsed.revision !== 'number' ||
      !parsed.draft?.settings ||
      !parsed.base?.settings ||
      Date.now() - parsed.savedAt > MAX_AGE_MS
    ) {
      clearDraft(userId, roomId)
      return null
    }
    return parsed
  } catch {
    return null
  }
}

export function writeDraft(userId: string, roomId: string, draft: StoredDraft): void {
  try {
    window.sessionStorage.setItem(keyFor(userId, roomId), JSON.stringify(draft))
  } catch {
    // Storage is a convenience here; the editor keeps working without it.
  }
}

export function clearDraft(userId: string, roomId: string): void {
  try {
    window.sessionStorage.removeItem(keyFor(userId, roomId))
  } catch {
    // As above.
  }
}

/** Remove every user's Floorplan draft from this tab — part of signing out. */
export function clearFloorplanDrafts(): void {
  removeDrafts(() => true)
}

/** Remove every Floorplan draft in this tab except `userId`'s — part of signing in. */
export function clearFloorplanDraftsExcept(userId: string): void {
  removeDrafts((key) => !key.startsWith(`${PREFIX}${userId}.`))
}

function removeDrafts(shouldRemove: (key: string) => boolean): void {
  try {
    const keys: string[] = []
    for (let i = 0; i < window.sessionStorage.length; i++) {
      const key = window.sessionStorage.key(i)
      if (key?.startsWith(PREFIX) && shouldRemove(key)) keys.push(key)
    }
    for (const key of keys) window.sessionStorage.removeItem(key)
  } catch {
    // As above.
  }
}

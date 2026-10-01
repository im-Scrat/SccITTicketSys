/**
 * The per-tab session lease (backend: `EnsureTabLeaseIsValid`).
 *
 * Deliberately `sessionStorage`, never `localStorage` or a cookie: it is the
 * one piece of this app's auth state that *must not* survive past this tab.
 * `sessionStorage` already has exactly that lifetime — new tab, empty store;
 * refresh or in-app navigation, untouched — so there is nothing extra to
 * manage here beyond get/set/clear.
 */
const STORAGE_KEY = 'sccit.tabLease'

export function getLease(): string | null {
  try {
    return sessionStorage.getItem(STORAGE_KEY)
  } catch {
    // Private-browsing storage block, etc. — treat as "no lease", same as a
    // genuinely fresh tab: the user signs in again rather than the app
    // crashing on a storage access it cannot guarantee.
    return null
  }
}

export function setLease(token: string): void {
  try {
    sessionStorage.setItem(STORAGE_KEY, token)
  } catch {
    // Nothing to fall back to; the tab will simply be asked to sign in again
    // on its next request, same as if storage had never worked.
  }
}

export function clearLease(): void {
  try {
    sessionStorage.removeItem(STORAGE_KEY)
  } catch {
    // No-op: there is nothing left to clear if storage already refused us.
  }
}

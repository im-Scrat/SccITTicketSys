/**
 * Theme resolution + persistence. The public site is dual-mode by construction
 * (DESIGN.md: light "day shift" / dark "night shift" are equal citizens). We
 * persist the user's *preference* ('light' | 'dark' | 'system'); the resolved
 * mode is applied to <html data-theme> where the CSS token overrides live.
 *
 * The anti-FOUC snippet in index.html mirrors this logic so the first paint is
 * already correct; this module is the source of truth after hydration.
 */

export type ThemePreference = 'light' | 'dark' | 'system'
export type ResolvedTheme = 'light' | 'dark'

export const THEME_STORAGE_KEY = 'sccit-theme'

const SYSTEM_QUERY = '(prefers-color-scheme: dark)'

export function getSystemTheme(): ResolvedTheme {
  if (typeof window === 'undefined' || !window.matchMedia) return 'light'
  return window.matchMedia(SYSTEM_QUERY).matches ? 'dark' : 'light'
}

export function resolveTheme(preference: ThemePreference): ResolvedTheme {
  return preference === 'system' ? getSystemTheme() : preference
}

export function getStoredPreference(): ThemePreference {
  try {
    const value = localStorage.getItem(THEME_STORAGE_KEY)
    if (value === 'light' || value === 'dark' || value === 'system') return value
  } catch {
    /* localStorage may be unavailable (private mode, blocked cookies) */
  }
  return 'system'
}

export function storePreference(preference: ThemePreference): void {
  try {
    localStorage.setItem(THEME_STORAGE_KEY, preference)
  } catch {
    /* non-fatal: theme simply won't persist */
  }
}

export function applyTheme(resolved: ResolvedTheme): void {
  if (typeof document === 'undefined') return
  const root = document.documentElement
  root.setAttribute('data-theme', resolved)
  root.style.colorScheme = resolved
}

/** Subscribe to OS theme changes. Returns an unsubscribe fn. */
export function onSystemThemeChange(listener: (theme: ResolvedTheme) => void): () => void {
  if (typeof window === 'undefined' || !window.matchMedia) return () => {}
  const mql = window.matchMedia(SYSTEM_QUERY)
  const handler = (event: MediaQueryListEvent) => listener(event.matches ? 'dark' : 'light')
  mql.addEventListener('change', handler)
  return () => mql.removeEventListener('change', handler)
}

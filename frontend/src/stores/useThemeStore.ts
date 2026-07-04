import { create } from 'zustand'
import {
  applyTheme,
  getStoredPreference,
  resolveTheme,
  storePreference,
  type ResolvedTheme,
  type ThemePreference,
} from '@/lib/theme'

interface ThemeState {
  /** What the user chose. */
  preference: ThemePreference
  /** The mode currently applied to the document (preference resolved through the OS). */
  resolved: ResolvedTheme
  /** Set an explicit preference and apply it immediately. */
  setPreference: (preference: ThemePreference) => void
  /** Flip between light and dark based on what's currently showing. */
  toggle: () => void
  /** Re-resolve after an OS-level change (only meaningful while preference is 'system'). */
  syncSystem: () => void
}

const initialPreference = getStoredPreference()

export const useThemeStore = create<ThemeState>((set, get) => ({
  preference: initialPreference,
  resolved: resolveTheme(initialPreference),

  setPreference: (preference) => {
    const resolved = resolveTheme(preference)
    storePreference(preference)
    applyTheme(resolved)
    set({ preference, resolved })
  },

  toggle: () => {
    const next: ThemePreference = get().resolved === 'dark' ? 'light' : 'dark'
    get().setPreference(next)
  },

  syncSystem: () => {
    if (get().preference !== 'system') return
    const resolved = resolveTheme('system')
    applyTheme(resolved)
    set({ resolved })
  },
}))

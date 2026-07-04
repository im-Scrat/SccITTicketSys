import { useEffect, type ReactNode } from 'react'
import { onSystemThemeChange } from '@/lib/theme'
import { useThemeStore } from '@/stores/useThemeStore'

/**
 * Keeps the document in sync with the theme store. The anti-FOUC script in
 * index.html sets the correct mode before first paint; this component (a) keeps
 * the DOM aligned with store state after hydration and (b) reacts to OS theme
 * changes while the user's preference is 'system'.
 */
export function ThemeProvider({ children }: { children: ReactNode }) {
  const resolved = useThemeStore((s) => s.resolved)
  const syncSystem = useThemeStore((s) => s.syncSystem)

  // Reflect store → document. Cheap and idempotent; guarantees alignment even
  // if the store is mutated from elsewhere.
  useEffect(() => {
    document.documentElement.setAttribute('data-theme', resolved)
    document.documentElement.style.colorScheme = resolved
  }, [resolved])

  // Follow the OS while preference is 'system'.
  useEffect(() => onSystemThemeChange(syncSystem), [syncSystem])

  return children
}

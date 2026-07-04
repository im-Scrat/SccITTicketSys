import { Monitor, Moon, Sun } from 'lucide-react'
import { cn } from '@/lib/cn'
import { useThemeStore } from '@/stores/useThemeStore'
import type { ThemePreference } from '@/lib/theme'

/**
 * Quick light/dark toggle for the nav. Flips based on what's currently showing;
 * after a toggle the choice is an explicit preference (no longer 'system').
 */
export function ThemeToggle({ className }: { className?: string }) {
  const resolved = useThemeStore((s) => s.resolved)
  const toggle = useThemeStore((s) => s.toggle)
  const target = resolved === 'dark' ? 'light' : 'dark'

  return (
    <button
      type="button"
      onClick={toggle}
      className={cn(
        'inline-flex size-9 items-center justify-center rounded-sm text-muted transition-colors duration-150 hover:bg-surface-sunken hover:text-ink',
        className,
      )}
      aria-label={`Switch to ${target} theme`}
      title={`Switch to ${target} theme`}
    >
      {resolved === 'dark' ? (
        <Sun size={18} aria-hidden="true" />
      ) : (
        <Moon size={18} aria-hidden="true" />
      )}
    </button>
  )
}

const OPTIONS: { value: ThemePreference; label: string; Icon: typeof Sun }[] = [
  { value: 'light', label: 'Light', Icon: Sun },
  { value: 'system', label: 'System', Icon: Monitor },
  { value: 'dark', label: 'Dark', Icon: Moon },
]

/**
 * Three-way theme control (light / system / dark) — exposes the 'system'
 * preference the quick toggle can't. Used where there's room to be explicit
 * (e.g. the footer).
 */
export function ThemeSegmented({ className }: { className?: string }) {
  const preference = useThemeStore((s) => s.preference)
  const setPreference = useThemeStore((s) => s.setPreference)

  return (
    <div
      className={cn(
        'inline-flex items-center gap-0.5 rounded-md border border-border bg-surface p-0.5',
        className,
      )}
      role="group"
      aria-label="Color theme"
    >
      {OPTIONS.map(({ value, label, Icon }) => {
        const active = preference === value
        return (
          <button
            key={value}
            type="button"
            onClick={() => setPreference(value)}
            aria-pressed={active}
            className={cn(
              'inline-flex items-center gap-1.5 rounded-sm px-2.5 py-1 text-xs font-medium transition-colors duration-150',
              active
                ? 'bg-primary-subtle text-primary-strong'
                : 'text-muted hover:bg-surface-sunken hover:text-ink',
            )}
          >
            <Icon size={14} aria-hidden="true" />
            {label}
          </button>
        )
      })}
    </div>
  )
}

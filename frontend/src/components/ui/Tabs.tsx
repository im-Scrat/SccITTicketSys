import { cn } from '@/lib/cn'

export interface TabItem {
  value: string
  label: string
  /** Optional count badge (e.g. audit entries). */
  count?: number
}

interface TabsProps {
  tabs: TabItem[]
  value: string
  onChange: (value: string) => void
  className?: string
}

/**
 * Underlined tab strip (role=tablist). The active tab carries an ink underline
 * and strong text — never color-only. Horizontally scrollable on narrow screens.
 */
export function Tabs({ tabs, value, onChange, className }: TabsProps) {
  return (
    <div
      role="tablist"
      className={cn('flex gap-1 overflow-x-auto border-b border-border', className)}
    >
      {tabs.map((tab) => {
        const active = tab.value === value
        return (
          <button
            key={tab.value}
            role="tab"
            type="button"
            aria-selected={active}
            onClick={() => onChange(tab.value)}
            className={cn(
              '-mb-px flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium',
              active
                ? 'border-primary text-ink-strong'
                : 'border-transparent text-muted hover:text-ink',
            )}
          >
            {tab.label}
            {tab.count !== undefined && (
              <span className="rounded-full bg-surface-sunken px-1.5 text-xs text-muted tnum">
                {tab.count}
              </span>
            )}
          </button>
        )
      })}
    </div>
  )
}

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
  /**
   * Share the width equally and let a long label wrap — for a narrow panel
   * (the floor-plan workstation list), where two tabs must always fit whole.
   */
  fill?: boolean
}

/**
 * Underlined tab strip (role=tablist). The active tab carries an ink underline
 * and strong text — never color-only.
 *
 * The strip wraps instead of scrolling. It used to be `overflow-x-auto` with
 * each tab pulled 1px over the border (`-mb-px`); that 1px overflowed the box
 * vertically, so every strip showed a scrollbar it never needed (owner report,
 * 2026-10-19), and the scroll box also clipped the keyboard focus ring. The
 * baseline is now an inset shadow the tabs' own underline paints over, so
 * nothing overflows and no tab is ever hidden behind a scroll.
 */
export function Tabs({ tabs, value, onChange, className, fill = false }: TabsProps) {
  return (
    <div
      role="tablist"
      className={cn(
        'flex gap-x-1 shadow-[inset_0_-1px_0_var(--color-border)]',
        // cn() does not merge conflicting utilities, so exactly one of these.
        fill ? 'flex-nowrap' : 'flex-wrap',
        className,
      )}
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
              'flex items-center border-b-2 py-2 text-sm font-medium',
              // Filled tabs pad less, so two short labels and a count fit on one
              // line in an 18rem panel; they still wrap rather than clip.
              fill
                ? 'min-w-0 flex-1 justify-center gap-1 px-1 text-center'
                : 'gap-1.5 whitespace-nowrap px-3',
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

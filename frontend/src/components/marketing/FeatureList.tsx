import type { LucideIcon } from 'lucide-react'
import { cn } from '@/lib/cn'

export interface FeatureItem {
  icon: LucideIcon
  title: string
  body: string
}

/**
 * A vertical list of icon + title + supporting line, shared by the split
 * feature sections so their rhythm stays identical while the copy varies.
 */
export function FeatureList({
  items,
  columns = 1,
  className,
}: {
  items: FeatureItem[]
  columns?: 1 | 2
  className?: string
}) {
  return (
    <ul
      className={cn(
        'grid gap-x-8 gap-y-6',
        columns === 2 ? 'sm:grid-cols-2' : 'grid-cols-1',
        className,
      )}
    >
      {items.map(({ icon: Icon, title, body }) => (
        <li key={title} className="flex gap-3.5">
          <span className="mt-0.5 inline-flex size-9 shrink-0 items-center justify-center rounded-md bg-surface-sunken text-primary-strong">
            <Icon size={18} aria-hidden="true" />
          </span>
          <div>
            <h3 className="text-[15px] font-semibold text-ink-strong">{title}</h3>
            <p className="mt-1 text-sm leading-relaxed text-muted">{body}</p>
          </div>
        </li>
      ))}
    </ul>
  )
}

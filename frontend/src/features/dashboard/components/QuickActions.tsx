import { ArrowRight } from 'lucide-react'
import { Link } from 'react-router-dom'
import { Badge } from '@/components/ui'
import type { ActionItem } from '../types'

/**
 * The one or two things this reader most likely came to do.
 *
 * An action whose module has not shipped yet renders as a disabled row with a
 * "Coming soon" marker rather than a link that 404s — telling someone the door
 * exists but is not open yet is kinder than letting them walk into it.
 */
export function QuickActions({ items }: { items: ActionItem[] }) {
  return (
    <ul className="flex flex-col gap-2">
      {items.map((item) => {
        const available = item.available !== false

        const body = (
          <>
            <span className="min-w-0">
              <span className="block text-sm font-medium text-ink-strong">{item.label}</span>
              {item.description && (
                <span className="mt-0.5 block text-xs text-muted">{item.description}</span>
              )}
            </span>
            {available ? (
              <ArrowRight size={16} className="shrink-0 text-muted" aria-hidden="true" />
            ) : (
              <Badge tone="outline">Coming soon</Badge>
            )}
          </>
        )

        return (
          <li key={item.key}>
            {available ? (
              <Link
                to={item.href}
                className="flex items-center justify-between gap-3 rounded-md border border-border bg-surface px-3 py-2.5 hover:bg-surface-sunken"
              >
                {body}
              </Link>
            ) : (
              <div
                aria-disabled="true"
                className="flex cursor-not-allowed items-center justify-between gap-3 rounded-md border border-border bg-surface-sunken px-3 py-2.5"
              >
                {body}
              </div>
            )}
          </li>
        )
      })}
    </ul>
  )
}

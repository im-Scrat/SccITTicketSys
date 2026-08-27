import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Surface } from '@/components/ui'
import { cn } from '@/lib/cn'

interface WidgetCardProps {
  title: string
  description?: string
  /** Optional link to the module this widget summarises. */
  href?: string
  linkLabel?: string
  children: ReactNode
  className?: string
}

/**
 * The frame every dashboard panel shares: one flat surface, a hairline border, a
 * quiet title. No nested cards and no shadow — panels sit *on* the canvas, they
 * do not float above it.
 */
export function WidgetCard({
  title,
  description,
  href,
  linkLabel = 'Open',
  children,
  className,
}: WidgetCardProps) {
  return (
    <Surface className={cn('flex flex-col p-4', className)}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <h2 className="text-sm font-semibold text-ink-strong">{title}</h2>
          {description && <p className="mt-0.5 text-xs text-muted">{description}</p>}
        </div>
        {href && (
          <Link
            to={href}
            className="shrink-0 rounded-sm text-xs font-medium text-primary-strong hover:underline"
          >
            {linkLabel}
          </Link>
        )}
      </div>
      <div className="mt-3 flex-1">{children}</div>
    </Surface>
  )
}

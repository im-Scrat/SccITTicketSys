import { useId, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Surface } from '@/components/ui'
import { cn } from '@/lib/cn'
import { PanelHeader } from './PanelHeader'

/**
 * `card` — the dashboard widget: title inside a small flat panel (admin and
 * technician dashboards).
 *
 * `section` — a page-level panel: the heading sits **above** a frame that is the
 * same frame a ticket card uses. The teacher dashboard asks for it so every
 * panel beside the community feed shares the feed's heading and frame instead of
 * sitting next to it in a lighter, smaller style.
 */
export type WidgetVariant = 'card' | 'section'

interface WidgetCardProps {
  title: string
  description?: string
  /** Optional link to the module this widget summarises. */
  href?: string
  linkLabel?: string
  children: ReactNode
  className?: string
  variant?: WidgetVariant
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
  variant = 'card',
}: WidgetCardProps) {
  const headingId = useId()

  if (variant === 'section') {
    return (
      <section aria-labelledby={headingId} className={cn('flex min-w-0 flex-col gap-4', className)}>
        <PanelHeader
          id={headingId}
          title={title}
          description={description}
          link={href ? { to: href, label: linkLabel } : undefined}
        />
        <div className="rounded-lg border-2 border-border bg-surface p-6">{children}</div>
      </section>
    )
  }

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

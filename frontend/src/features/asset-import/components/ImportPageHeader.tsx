import type { ReactNode } from 'react'

/**
 * The import pages' header: one h1, a line saying what the page is for, and
 * the page's actions on the right (wrapping under the title on narrow
 * screens). Mirrors the dashboard header's type scale without its live
 * status, which an import page has no use for.
 */
export function ImportPageHeader({
  title,
  description,
  meta,
  children,
}: {
  title: string
  description?: ReactNode
  meta?: ReactNode
  children?: ReactNode
}) {
  return (
    <header className="flex flex-wrap items-end justify-between gap-x-6 gap-y-4">
      <div className="measure min-w-0">
        <h1 className="break-words text-2xl font-semibold tracking-[-0.02em] text-ink-strong">
          {title}
        </h1>
        {description && <p className="mt-2 text-base text-muted">{description}</p>}
        {meta && (
          <div className="mt-3 flex flex-wrap items-center gap-2 text-sm text-muted">{meta}</div>
        )}
      </div>
      {children && <div className="flex flex-wrap gap-3">{children}</div>}
    </header>
  )
}

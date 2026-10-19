import type { ReactNode } from 'react'
import { PanelHeader } from '@/features/dashboard/components/PanelHeader'
import { cn } from '@/lib/cn'

/**
 * One framed section of the import wizard: the shared panel heading over a
 * flat frame. Sub-sections inside are unframed — no cards inside cards.
 */
export function ImportPanel({
  id,
  title,
  description,
  actions,
  children,
  className,
}: {
  id?: string
  title: string
  description?: ReactNode
  actions?: ReactNode
  children: ReactNode
  className?: string
}) {
  return (
    <section aria-labelledby={id} className={cn('flex min-w-0 flex-col gap-4', className)}>
      <PanelHeader id={id} title={title} description={description} actions={actions} />
      <div className="min-w-0 rounded-lg border-2 border-border bg-surface p-4 sm:p-6">
        {children}
      </div>
    </section>
  )
}

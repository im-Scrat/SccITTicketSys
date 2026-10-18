import { AlertCircle, RefreshCw } from 'lucide-react'
import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'
import { Button } from './Button'

interface ErrorStateProps {
  title: string
  description?: ReactNode
  onRetry?: () => void
  retrying?: boolean
  className?: string
}

/**
 * A panel that could not load, said plainly, with the way out. Scoped to the
 * panel, so one failed request never blanks the page around it; announced as
 * an alert. Never red as decoration: the icon and the words carry it.
 */
export function ErrorState({ title, description, onRetry, retrying, className }: ErrorStateProps) {
  return (
    <div
      role="alert"
      className={cn(
        'flex flex-col items-start gap-3 rounded-md border border-border bg-surface-sunken p-5',
        className,
      )}
    >
      <p className="flex items-center gap-2 text-base font-semibold text-ink-strong">
        <AlertCircle size={20} aria-hidden="true" className="shrink-0 text-danger-strong" />
        {title}
      </p>
      {description && <p className="text-sm text-muted">{description}</p>}
      {onRetry && (
        <Button
          variant="secondary"
          size="sm"
          leftIcon={<RefreshCw size={16} aria-hidden="true" />}
          loading={retrying}
          onClick={onRetry}
        >
          Try again
        </Button>
      )}
    </div>
  )
}

import { RefreshCw } from 'lucide-react'
import type { ReactNode } from 'react'
import { Button } from '@/components/ui'
import { formatRelative } from '@/lib/datetime'

interface DashboardHeaderProps {
  title: string
  subtitle: string
  generatedAt?: string | null
  refreshing: boolean
  onRefresh: () => void
  /** Role-specific primary action and status, placed before Refresh. */
  children?: ReactNode
}

/** The one header every role dashboard shares, so the three read as one product. */
export function DashboardHeader({
  title,
  subtitle,
  generatedAt,
  refreshing,
  onRefresh,
  children,
}: DashboardHeaderProps) {
  return (
    <header className="flex flex-wrap items-start justify-between gap-4">
      <div className="min-w-0">
        <h1 className="text-2xl font-semibold tracking-[-0.02em] text-balance text-ink-strong">
          {title}
        </h1>
        <p className="mt-1 text-sm text-muted">{subtitle}</p>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        {children}
        {generatedAt && (
          <span className="text-xs text-muted tnum">Updated {formatRelative(generatedAt)}</span>
        )}
        <Button
          variant="secondary"
          size="sm"
          leftIcon={<RefreshCw size={15} />}
          loading={refreshing}
          onClick={onRefresh}
        >
          Refresh
        </Button>
      </div>
    </header>
  )
}

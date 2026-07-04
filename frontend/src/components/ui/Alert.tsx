import { AlertCircle, AlertTriangle, CheckCircle2, Info, type LucideIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { cn } from '@/lib/cn'

export type AlertTone = 'error' | 'success' | 'warning' | 'info'

interface ToneStyle {
  wash: string
  text: string
  icon: LucideIcon
}

/**
 * Inline status banner: subtle wash + strong same-hue text + a leading icon
 * (never a colored side-stripe — DESIGN.md). Errors use role="alert" so they are
 * announced; other tones use role="status".
 */
const tones: Record<AlertTone, ToneStyle> = {
  error: { wash: 'bg-danger-subtle', text: 'text-danger-strong', icon: AlertCircle },
  success: { wash: 'bg-success-subtle', text: 'text-success-strong', icon: CheckCircle2 },
  warning: { wash: 'bg-warning-subtle', text: 'text-warning-strong', icon: AlertTriangle },
  info: { wash: 'bg-info-subtle', text: 'text-info', icon: Info },
}

interface AlertProps {
  tone?: AlertTone
  title?: string
  children?: ReactNode
  className?: string
}

export function Alert({ tone = 'info', title, children, className }: AlertProps) {
  const style = tones[tone]
  const Icon = style.icon

  return (
    <div
      role={tone === 'error' ? 'alert' : 'status'}
      className={cn(
        'flex gap-2.5 rounded-md border border-border p-3 text-sm',
        style.wash,
        style.text,
        className,
      )}
    >
      <Icon size={16} className="mt-0.5 shrink-0" aria-hidden="true" />
      <div className="min-w-0">
        {title && <p className="font-medium">{title}</p>}
        {children && <div className={cn(title && 'mt-0.5', 'text-ink')}>{children}</div>}
      </div>
    </div>
  )
}

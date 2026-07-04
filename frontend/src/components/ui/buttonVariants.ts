import { cn } from '@/lib/cn'

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger'
export type ButtonSize = 'sm' | 'md' | 'lg'

const base =
  'relative inline-flex select-none items-center justify-center gap-2 whitespace-nowrap rounded-sm font-medium transition-[background-color,border-color,color,box-shadow] duration-150 [transition-timing-function:var(--ease-standard)] disabled:cursor-not-allowed'

const variants: Record<ButtonVariant, string> = {
  // The one filled-color button on a surface (One Voice Rule).
  primary:
    'bg-primary text-on-primary hover:bg-primary-hover active:bg-primary-active disabled:bg-surface-sunken disabled:text-faint',
  // The default for most actions: bordered, neutral, no color spend.
  secondary:
    'border border-control-border bg-surface text-ink hover:bg-surface-sunken disabled:border-border disabled:bg-surface-sunken disabled:text-faint',
  // Toolbar / low-emphasis.
  ghost:
    'bg-transparent text-ink hover:bg-surface-sunken disabled:bg-transparent disabled:text-faint',
  // Destructive only.
  danger:
    'bg-[var(--danger-fill)] text-[var(--danger-on)] hover:brightness-95 active:brightness-90 disabled:bg-surface-sunken disabled:text-faint',
}

const sizes: Record<ButtonSize, string> = {
  sm: 'h-8 px-3 text-[0.8125rem]',
  md: 'h-[2.375rem] px-4 text-sm',
  lg: 'h-11 px-5 text-[0.9375rem]',
}

export function buttonVariants(
  variant: ButtonVariant = 'primary',
  size: ButtonSize = 'md',
): string {
  return cn(base, variants[variant], sizes[size])
}

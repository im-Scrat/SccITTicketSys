import { cn } from '@/lib/cn'

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger'
export type ButtonSize = 'sm' | 'md' | 'lg'

/*
 * Phase 2.5 "no-zoom" sizing. The default control is 60px tall with 18px medium
 * label text — a target that does not need aiming at. `sm` (48px) exists for
 * dense toolbars and inline row actions, and is still well above the 44px
 * minimum; there is deliberately no size below it.
 *
 * Every variant carries a visible border, including the filled ones. Borderless
 * "flat" buttons read as text to someone who is not looking for them, and the
 * directive asks for controls that are unmistakably clickable.
 */
const base =
  'relative inline-flex select-none items-center justify-center gap-2.5 whitespace-nowrap rounded-md border font-semibold transition-[background-color,border-color,color,box-shadow] duration-150 [transition-timing-function:var(--ease-standard)] disabled:cursor-not-allowed'

const variants: Record<ButtonVariant, string> = {
  // The one filled-color button on a surface (One Voice Rule).
  primary:
    'border-primary bg-primary text-on-primary shadow-sm hover:border-primary-hover hover:bg-primary-hover active:bg-primary-active disabled:border-border disabled:bg-surface-sunken disabled:text-faint disabled:shadow-none',
  // The default for most actions: bordered, neutral, no color spend.
  secondary:
    'border-control-border bg-surface text-ink shadow-sm hover:bg-surface-sunken disabled:border-border disabled:bg-surface-sunken disabled:text-faint disabled:shadow-none',
  // Toolbar / low-emphasis — still bordered, just quieter.
  ghost:
    'border-transparent bg-transparent text-ink hover:border-control-border hover:bg-surface-sunken disabled:bg-transparent disabled:text-faint',
  // Destructive only.
  danger:
    'border-[var(--danger-fill)] bg-[var(--danger-fill)] text-[var(--danger-on)] shadow-sm hover:brightness-110 active:brightness-95 disabled:border-border disabled:bg-surface-sunken disabled:text-faint disabled:shadow-none',
}

const sizes: Record<ButtonSize, string> = {
  sm: 'h-12 px-4 text-sm',
  md: 'h-15 px-6 text-sm', // 60px
  lg: 'h-16 px-8 text-base',
}

export function buttonVariants(
  variant: ButtonVariant = 'primary',
  size: ButtonSize = 'md',
): string {
  return cn(base, variants[variant], sizes[size])
}

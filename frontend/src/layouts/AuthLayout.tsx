import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Logo } from '@/components/ui/Logo'
import { Surface } from '@/components/ui/Surface'
import { ThemeToggle } from '@/components/ui/ThemeToggle'

interface AuthLayoutProps {
  children: ReactNode
  title?: string
  subtitle?: ReactNode
  icon?: ReactNode
  /** Rendered under the card (e.g. secondary links). */
  footer?: ReactNode
}

/**
 * Shared shell for the standalone auth screens: brand + theme toggle header and
 * a single centered card. Extracted from the original Sign-in entry so every
 * auth page reads identically. Not wrapped in PublicLayout (no marketing chrome).
 */
export function AuthLayout({ children, title, subtitle, icon, footer }: AuthLayoutProps) {
  return (
    <div className="flex min-h-dvh flex-col bg-bg">
      <header className="flex items-center justify-between px-5 py-5 sm:px-8">
        <Link to="/" className="rounded-sm" aria-label="SccIT — home">
          <Logo />
        </Link>
        <ThemeToggle />
      </header>

      <main className="flex flex-1 items-center justify-center px-5 pb-16">
        <div className="w-full max-w-md">
          <Surface className="animate-sheet-in p-8">
            {icon && (
              <span className="mb-5 inline-flex size-11 items-center justify-center rounded-md bg-primary-subtle text-primary-strong">
                {icon}
              </span>
            )}
            {title && (
              <h1 className="text-2xl font-semibold tracking-[-0.02em] text-ink-strong">{title}</h1>
            )}
            {subtitle && <p className="mt-2.5 text-sm leading-relaxed text-muted">{subtitle}</p>}
            <div className={title || subtitle ? 'mt-7' : undefined}>{children}</div>
          </Surface>
          {footer && <div className="mt-6 text-center text-sm text-muted">{footer}</div>}
        </div>
      </main>
    </div>
  )
}

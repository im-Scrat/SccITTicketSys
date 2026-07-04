import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Menu } from 'lucide-react'
import { cn } from '@/lib/cn'
import { useScrolled } from '@/hooks/useScrolled'
import { useScrollSpy } from '@/hooks/useScrollSpy'
import { ButtonLink } from '@/components/ui/Button'
import { Container } from '@/components/ui/Container'
import { Logo } from '@/components/ui/Logo'
import { ThemeToggle } from '@/components/ui/ThemeToggle'
import { MobileNav } from './MobileNav'
import { primaryNav } from './navigation'

const spyIds = primaryNav
  .map((link) => link.href?.slice(1))
  .filter((id): id is string => Boolean(id))

export function Navbar() {
  const [menuOpen, setMenuOpen] = useState(false)
  const scrolled = useScrolled(8)
  const activeId = useScrollSpy(spyIds)

  return (
    <header
      className={cn(
        'sticky top-0 z-[1100] border-b bg-surface transition-colors duration-200',
        scrolled ? 'border-border' : 'border-transparent',
      )}
    >
      <Container>
        <div className="flex h-16 items-center justify-between gap-4">
          <Link to="/" className="rounded-sm focus-visible:outline-2" aria-label="SccIT — home">
            <Logo />
          </Link>

          <nav className="hidden items-center gap-1 lg:flex" aria-label="Primary">
            {primaryNav.map((link) => {
              const isActive = activeId != null && link.href === `#${activeId}`
              return (
                <a
                  key={link.label}
                  href={link.href}
                  aria-current={isActive ? 'true' : undefined}
                  className={cn(
                    'relative rounded-sm px-3 py-1.5 text-sm font-medium transition-colors duration-150',
                    isActive
                      ? 'text-ink-strong after:absolute after:inset-x-3 after:-bottom-0.5 after:h-0.5 after:rounded-full after:bg-primary'
                      : 'text-muted hover:text-ink',
                  )}
                >
                  {link.label}
                </a>
              )
            })}
          </nav>

          <div className="flex items-center gap-1.5">
            <ThemeToggle />
            <ButtonLink to="/sign-in" variant="ghost" size="sm" className="hidden lg:inline-flex">
              Sign in
            </ButtonLink>
            <ButtonLink href="#demo" variant="primary" size="sm" className="hidden sm:inline-flex">
              Book a demo
            </ButtonLink>
            <button
              type="button"
              className="inline-flex size-9 items-center justify-center rounded-sm text-ink transition-colors duration-150 hover:bg-surface-sunken lg:hidden"
              aria-label="Open navigation"
              aria-expanded={menuOpen}
              aria-haspopup="dialog"
              onClick={() => setMenuOpen(true)}
            >
              <Menu size={20} aria-hidden="true" />
            </button>
          </div>
        </div>
      </Container>

      <MobileNav open={menuOpen} onClose={() => setMenuOpen(false)} />
    </header>
  )
}

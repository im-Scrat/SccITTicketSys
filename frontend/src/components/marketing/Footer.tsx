import { Link } from 'react-router-dom'
import { Mail } from 'lucide-react'
import { Container } from '@/components/ui/Container'
import { Logo } from '@/components/ui/Logo'
import { ThemeSegmented } from '@/components/ui/ThemeToggle'
import { CONTACT_EMAIL, footerColumns, type NavLink } from './navigation'

function FooterLink({ link }: { link: NavLink }) {
  const className = 'text-sm text-muted transition-colors duration-150 hover:text-ink'
  if (link.to) {
    return (
      <Link to={link.to} className={className}>
        {link.label}
      </Link>
    )
  }
  return (
    <a
      href={link.href}
      className={className}
      {...(link.external ? { target: '_blank', rel: 'noreferrer' } : {})}
    >
      {link.label}
    </a>
  )
}

export function Footer() {
  const year = new Date().getFullYear()

  return (
    <footer className="border-t border-border bg-surface">
      <Container className="py-14">
        <div className="grid grid-cols-2 gap-x-6 gap-y-10 sm:grid-cols-3 lg:grid-cols-[1.7fr_repeat(4,1fr)]">
          <div className="col-span-2 sm:col-span-3 lg:col-span-1">
            <Logo />
            <p className="mt-4 max-w-xs text-sm leading-relaxed text-muted">
              The operations console for IT asset &amp; service management — one calm, dense
              platform that re-skins for any organization.
            </p>
            <a
              href={`mailto:${CONTACT_EMAIL}`}
              className="mt-4 inline-flex items-center gap-2 text-sm font-medium text-primary-strong transition-colors duration-150 hover:text-primary"
            >
              <Mail size={15} aria-hidden="true" />
              {CONTACT_EMAIL}
            </a>
          </div>

          {footerColumns.map((column) => (
            <nav key={column.title} aria-label={column.title}>
              <h2 className="text-xs font-semibold uppercase tracking-[0.04em] text-muted">
                {column.title}
              </h2>
              <ul className="mt-3.5 space-y-2.5">
                {column.links.map((link) => (
                  <li key={link.label}>
                    <FooterLink link={link} />
                  </li>
                ))}
              </ul>
            </nav>
          ))}
        </div>
      </Container>

      <div className="border-t border-border">
        <Container className="flex flex-col items-start justify-between gap-4 py-5 sm:flex-row sm:items-center">
          <p className="text-xs text-muted">
            © {year} SccIT · Vertical-agnostic ITSM / ITAM platform. Built for accessibility (WCAG
            2.2 AA).
          </p>
          <ThemeSegmented />
        </Container>
      </div>
    </footer>
  )
}

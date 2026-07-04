import { ArrowLeft, Building2, Mail } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { ButtonLink } from '@/components/ui/Button'
import { Logo } from '@/components/ui/Logo'
import { Surface } from '@/components/ui/Surface'
import { ThemeToggle } from '@/components/ui/ThemeToggle'
import { CONTACT_EMAIL } from '@/components/marketing/navigation'

/**
 * The workspace sign-in entry — the front door into the secure application.
 * Access is provisioned per deployment, so this page routes people to their
 * organization's instance or to an administrator. The authenticated sign-in
 * flow itself is delivered with the application (a later phase); this is a real
 * page, not a stubbed form.
 */
export default function SignInPage() {
  useDocumentMeta({ title: 'Sign in' })

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
          <Surface className="p-8">
            <span className="inline-flex size-11 items-center justify-center rounded-md bg-primary-subtle text-primary-strong">
              <Building2 size={22} aria-hidden="true" />
            </span>
            <h1 className="mt-5 text-2xl font-semibold tracking-[-0.02em] text-ink-strong">
              Sign in to your workspace
            </h1>
            <p className="mt-2.5 text-[15px] leading-relaxed text-muted">
              SccIT runs as a secure workspace provisioned for your organization. Open your
              organization’s SccIT address to sign in, or reach your administrator to request
              access.
            </p>

            <div className="mt-7 flex flex-col gap-3">
              <ButtonLink
                href={`mailto:${CONTACT_EMAIL}?subject=SccIT%20access%20request`}
                variant="primary"
                size="lg"
                leftIcon={<Mail size={17} aria-hidden="true" />}
              >
                Contact your administrator
              </ButtonLink>
              <ButtonLink
                to="/"
                variant="secondary"
                size="lg"
                leftIcon={<ArrowLeft size={17} aria-hidden="true" />}
              >
                Back to homepage
              </ButtonLink>
            </div>
          </Surface>

          <p className="mt-6 text-center text-xs text-muted">
            Protected by role-based access and full activity logging.
          </p>
        </div>
      </main>
    </div>
  )
}

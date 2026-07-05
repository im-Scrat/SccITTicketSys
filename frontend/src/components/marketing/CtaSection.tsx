import { ArrowRight } from 'lucide-react'
import { ButtonLink } from '@/components/ui/Button'
import { Container } from '@/components/ui/Container'
import { Logo } from '@/components/ui/Logo'
import { Reveal } from '@/components/ui/Reveal'
import { CONTACT_EMAIL } from './navigation'

export function CtaSection() {
  return (
    <section id="get-started" className="scroll-mt-20 py-20 lg:py-28">
      <Container>
        <Reveal>
          <div className="overflow-hidden rounded-lg border border-border bg-surface-sunken px-6 py-14 text-center sm:px-12">
            <Logo withWordmark={false} size={36} className="justify-center" />
            <h2 className="mx-auto mt-6 max-w-2xl text-[1.75rem] font-semibold leading-[1.15] tracking-[-0.02em] text-ink-strong sm:text-[2.25rem]">
              Bring your IT operations into one console.
            </h2>
            <p className="mx-auto mt-4 max-w-xl text-[1.0625rem] leading-relaxed text-muted">
              See how SccIT unifies ticketing, assets, maintenance, and analytics — and how quickly
              it re-skins for your organization.
            </p>

            <div className="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
              <ButtonLink
                to="/sign-in"
                variant="primary"
                size="lg"
                rightIcon={<ArrowRight size={18} aria-hidden="true" />}
              >
                Sign in
              </ButtonLink>
            </div>

            <p className="mt-6 text-sm text-muted">
              Or email us at{' '}
              <a
                href={`mailto:${CONTACT_EMAIL}`}
                className="font-medium text-primary-strong transition-colors duration-150 hover:text-primary"
              >
                {CONTACT_EMAIL}
              </a>
            </p>
          </div>
        </Reveal>
      </Container>
    </section>
  )
}

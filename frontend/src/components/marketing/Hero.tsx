import { ArrowRight, KeyRound, Server, ShieldCheck, Sparkles } from 'lucide-react'
import { ButtonLink } from '@/components/ui/Button'
import { Container } from '@/components/ui/Container'
import { Reveal } from '@/components/ui/Reveal'
import { ConsolePreview } from './visuals/ConsolePreview'

const trustPoints = [
  { icon: ShieldCheck, label: 'WCAG 2.2 AA' },
  { icon: KeyRound, label: 'Role-based access' },
  { icon: Server, label: 'Self-hosted' },
]

export function Hero() {
  return (
    <section className="relative overflow-hidden border-b border-border">
      <Container className="py-16 lg:py-24">
        <div className="grid items-center gap-12 lg:grid-cols-12 lg:gap-8">
          <div className="lg:col-span-5">
            <Reveal>
              <a
                href="#intelligence"
                className="inline-flex items-center gap-2 rounded-full border border-border bg-surface py-1 pl-1 pr-3 text-xs font-medium text-ink transition-colors duration-150 hover:bg-surface-sunken"
              >
                <span className="inline-flex items-center gap-1 rounded-full bg-primary-subtle px-2 py-0.5 text-primary-strong">
                  <Sparkles size={12} aria-hidden="true" />
                  New
                </span>
                Grounded AI, governed by default
                <ArrowRight size={13} aria-hidden="true" className="text-muted" />
              </a>

              <h1 className="mt-5 text-[2.5rem] font-semibold leading-[1.05] tracking-[-0.03em] text-ink-strong sm:text-[3rem] lg:text-[3.25rem]">
                Every asset, request, and repair in one operations console.
              </h1>

              <p className="mt-5 max-w-xl text-[1.0625rem] leading-relaxed text-muted">
                SccIT unifies IT service and asset management — ticketing with SLAs, the full asset
                lifecycle, preventive maintenance, QR tracking, interactive floor plans, grounded
                AI, and analytics. One calm, dense platform, built to deploy for any organization.
              </p>

              <div className="mt-8 flex flex-col gap-3 sm:flex-row">
                <ButtonLink
                  href="#platform"
                  variant="primary"
                  size="lg"
                  rightIcon={<ArrowRight size={18} aria-hidden="true" />}
                >
                  Explore the platform
                </ButtonLink>
                <ButtonLink to="/sign-in" variant="secondary" size="lg">
                  Sign in
                </ButtonLink>
              </div>

              <ul className="mt-8 flex flex-wrap items-center gap-x-5 gap-y-2">
                {trustPoints.map(({ icon: Icon, label }) => (
                  <li key={label} className="inline-flex items-center gap-1.5 text-xs text-muted">
                    <Icon size={15} className="text-faint" aria-hidden="true" />
                    {label}
                  </li>
                ))}
              </ul>
            </Reveal>
          </div>

          <div className="lg:col-span-7">
            <Reveal delay={120}>
              <ConsolePreview />
            </Reveal>
          </div>
        </div>
      </Container>
    </section>
  )
}

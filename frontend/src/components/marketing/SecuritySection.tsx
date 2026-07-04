import type { LucideIcon } from 'lucide-react'
import { Accessibility, FileCheck2, Fingerprint, KeyRound, Lock, ShieldCheck } from 'lucide-react'
import { Container } from '@/components/ui/Container'
import { Reveal } from '@/components/ui/Reveal'
import { SectionHeading } from '@/components/ui/SectionHeading'

const properties: { icon: LucideIcon; title: string; body: string }[] = [
  {
    icon: KeyRound,
    title: 'Cookie-based SPA auth',
    body: 'First-party Sanctum sessions on a single origin — no cross-site tokens to leak or manage.',
  },
  {
    icon: ShieldCheck,
    title: 'Role-based access control',
    body: 'Granular permissions resolved per user and enforced at every layer, from route to record.',
  },
  {
    icon: FileCheck2,
    title: 'Tamper-evident audit',
    body: 'Row-level changes captured in an append-only log the database itself forbids editing.',
  },
  {
    icon: Fingerprint,
    title: 'Opaque addressing',
    body: 'UUID route keys everywhere — internal numeric IDs never appear in URLs or API payloads.',
  },
  {
    icon: Lock,
    title: 'Hardened by default',
    body: 'CSRF protection, throttling on sensitive routes, and server-side validation mirrored on the client.',
  },
  {
    icon: Accessibility,
    title: 'Accessible & self-hosted',
    body: 'WCAG 2.2 AA across the product, with self-hosted fonts and assets — no third-party CDNs.',
  },
]

export function SecuritySection() {
  return (
    <section id="security" className="scroll-mt-20 py-20 lg:py-28">
      <Container>
        <Reveal>
          <SectionHeading
            title="Enterprise security, not an afterthought."
            lead="The controls procurement asks about are built into the foundation — access, auditability, and accessibility verified from the first commit, not retrofitted."
          />
        </Reveal>

        <div className="mt-12 grid gap-x-8 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
          {properties.map(({ icon: Icon, title, body }, i) => (
            <Reveal key={title} delay={(i % 3) * 60}>
              <div>
                <span className="inline-flex size-10 items-center justify-center rounded-md bg-surface-sunken text-primary-strong">
                  <Icon size={19} aria-hidden="true" />
                </span>
                <h3 className="mt-4 text-base font-semibold text-ink-strong">{title}</h3>
                <p className="mt-1.5 text-sm leading-relaxed text-muted">{body}</p>
              </div>
            </Reveal>
          ))}
        </div>
      </Container>
    </section>
  )
}

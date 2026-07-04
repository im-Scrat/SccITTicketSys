import type { LucideIcon } from 'lucide-react'
import { BellRing, BookOpen, Boxes, ShieldCheck, Ticket, Wrench } from 'lucide-react'
import { cn } from '@/lib/cn'
import { Container } from '@/components/ui/Container'
import { Reveal } from '@/components/ui/Reveal'
import { SectionHeading } from '@/components/ui/SectionHeading'
import { Surface } from '@/components/ui/Surface'

function IconTile({ icon: Icon }: { icon: LucideIcon }) {
  return (
    <span className="inline-flex size-9 items-center justify-center rounded-md bg-surface-sunken text-primary-strong">
      <Icon size={18} aria-hidden="true" />
    </span>
  )
}

const secondary = [
  {
    icon: Boxes,
    title: 'Asset lifecycle',
    body: 'PC units, serialized assets, consumables, and stock — with procurement, transfers, and disposal tracked from acquisition to end of life.',
    span: 'md:col-span-3',
  },
  {
    icon: Wrench,
    title: 'Preventive maintenance',
    body: 'Corrective and scheduled maintenance with checklists, hardware replacements, and reminders that fire before things break.',
    span: 'md:col-span-3',
  },
  {
    icon: BookOpen,
    title: 'Knowledge base',
    body: 'Draft, publish, and search articles — grounding the assistant and giving requesters answers before they file.',
    span: 'md:col-span-2',
  },
  {
    icon: BellRing,
    title: 'Notifications',
    body: 'Multi-channel alerts that honor each person’s preferences, from SLA breaches to low-stock consumables.',
    span: 'md:col-span-2',
  },
  {
    icon: ShieldCheck,
    title: 'Roles & audit',
    body: 'Granular permissions with an append-only, tamper-evident audit trail on every change.',
    span: 'md:col-span-2',
  },
]

export function FeaturesSection() {
  return (
    <section id="platform" className="scroll-mt-20 py-20 lg:py-28">
      <Container>
        <Reveal>
          <SectionHeading
            title="One platform for the whole IT operation."
            lead="From the first request to end-of-life disposal, SccIT covers the capabilities an IT team actually runs on — unified, consistent, and calm at scale."
          />
        </Reveal>

        <div className="mt-12 grid grid-cols-1 gap-4 md:grid-cols-6">
          {/* Anchor tile */}
          <Reveal className="md:col-span-3 md:row-span-2">
            <Surface className="flex h-full flex-col p-6">
              <IconTile icon={Ticket} />
              <h3 className="mt-4 text-lg font-semibold text-ink-strong">Ticketing &amp; SLAs</h3>
              <p className="mt-2 text-sm leading-relaxed text-muted">
                Requests flow from guided intake through triage, assignment, comments, and votes.
                Priority-derived SLA timers track first response and resolution, and flag breaches
                before they happen.
              </p>

              <ul className="mt-5 space-y-2.5 text-sm text-ink">
                {[
                  'Guided intake with AI-assisted triage',
                  'Assignment, comments, votes, and duplicates',
                  'SLA timers with automatic breach detection',
                ].map((item) => (
                  <li key={item} className="flex items-start gap-2.5">
                    <span
                      className="mt-1.5 size-1.5 shrink-0 rounded-full bg-primary"
                      aria-hidden="true"
                    />
                    {item}
                  </li>
                ))}
              </ul>

              <div className="mt-auto flex flex-wrap gap-2 pt-6">
                {[
                  { label: 'Triage', cls: 'bg-info-subtle text-info' },
                  { label: 'In progress', cls: 'bg-primary-subtle text-primary-strong' },
                  { label: 'Resolved', cls: 'bg-success-subtle text-success-strong' },
                ].map((chip) => (
                  <span
                    key={chip.label}
                    className={cn('rounded-full px-2.5 py-0.5 text-xs font-medium', chip.cls)}
                  >
                    {chip.label}
                  </span>
                ))}
              </div>
            </Surface>
          </Reveal>

          {secondary.map((feature, i) => (
            <Reveal key={feature.title} className={feature.span} delay={i * 60}>
              <Surface className="flex h-full flex-col p-6">
                <IconTile icon={feature.icon} />
                <h3 className="mt-4 text-base font-semibold text-ink-strong">{feature.title}</h3>
                <p className="mt-2 text-sm leading-relaxed text-muted">{feature.body}</p>
              </Surface>
            </Reveal>
          ))}
        </div>
      </Container>
    </section>
  )
}

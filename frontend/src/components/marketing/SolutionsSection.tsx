import { useRef, useState } from 'react'
import type { LucideIcon } from 'lucide-react'
import { Building2, Check, GraduationCap, Landmark, Stethoscope } from 'lucide-react'
import { cn } from '@/lib/cn'
import { Container } from '@/components/ui/Container'
import { Reveal } from '@/components/ui/Reveal'
import { SectionHeading } from '@/components/ui/SectionHeading'
import { Surface } from '@/components/ui/Surface'

interface Vertical {
  id: string
  label: string
  icon: LucideIcon
  headline: string
  blurb: string
  points: string[]
}

const verticals: Vertical[] = [
  {
    id: 'education',
    label: 'Education',
    icon: GraduationCap,
    headline: 'Labs, classrooms, and shared devices',
    blurb:
      'The first deployment target — projectors, lab PCs, and staff laptops across buildings and rooms, reported by non-technical staff and triaged by technicians on the floor.',
    points: [
      'Per-room asset tracking and interactive floor plans',
      'Near-zero-learning-curve requests for teachers',
      'Preventive maintenance across high-use shared devices',
    ],
  },
  {
    id: 'business',
    label: 'Business',
    icon: Building2,
    headline: 'Offices, fleets, and internal service desks',
    blurb:
      'Corporate IT that assigns hardware to employees, runs an internal service desk against SLAs, and keeps procurement and stock under control.',
    points: [
      'Employee asset assignment, transfers, and disposal',
      'Service-desk SLAs, MTTR, and workload reporting',
      'Procurement approvals and consumable stock control',
    ],
  },
  {
    id: 'healthcare',
    label: 'Healthcare',
    icon: Stethoscope,
    headline: 'Device uptime with a defensible trail',
    blurb:
      'Environments where uptime and accountability matter — critical-asset status at a glance and an audit trail that holds up under scrutiny.',
    points: [
      'Tamper-evident, append-only audit on every change',
      'Scheduled maintenance windows and reminders',
      'Critical-asset status surfaced across the console',
    ],
  },
  {
    id: 'government',
    label: 'Government',
    icon: Landmark,
    headline: 'Public sector on locked-down networks',
    blurb:
      'Deployments with strict accessibility and network constraints — self-hosted, dependency-light, and accessible by construction.',
    points: [
      'Self-hosted with no third-party CDNs or trackers',
      'WCAG 2.2 AA verified across the whole product',
      'Role-based access with full activity logging',
    ],
  },
]

export function SolutionsSection() {
  const [active, setActive] = useState(0)
  const tabRefs = useRef<(HTMLButtonElement | null)[]>([])

  const focusTab = (index: number) => {
    const next = (index + verticals.length) % verticals.length
    setActive(next)
    tabRefs.current[next]?.focus()
  }

  const onKeyDown = (event: React.KeyboardEvent, index: number) => {
    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
      event.preventDefault()
      focusTab(index + 1)
    } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
      event.preventDefault()
      focusTab(index - 1)
    } else if (event.key === 'Home') {
      event.preventDefault()
      focusTab(0)
    } else if (event.key === 'End') {
      event.preventDefault()
      focusTab(verticals.length - 1)
    }
  }

  const current = verticals[active]

  return (
    <section
      id="solutions"
      className="scroll-mt-20 border-y border-border bg-surface py-20 lg:py-28"
    >
      <Container>
        <Reveal>
          <SectionHeading
            title="Built for one vertical. Architected for all of them."
            lead="SccIT ships for education first, but nothing hard-codes a classroom. Re-skin the entire platform for another organization by changing a single brand token — the structure and language stay the same."
          />
        </Reveal>

        <Reveal className="mt-10">
          <div role="tablist" aria-label="Example deployments" className="flex flex-wrap gap-2">
            {verticals.map((vertical, index) => {
              const selected = index === active
              const Icon = vertical.icon
              return (
                <button
                  key={vertical.id}
                  ref={(el) => {
                    tabRefs.current[index] = el
                  }}
                  role="tab"
                  id={`solutions-tab-${vertical.id}`}
                  aria-selected={selected}
                  aria-controls={`solutions-panel-${vertical.id}`}
                  tabIndex={selected ? 0 : -1}
                  onClick={() => setActive(index)}
                  onKeyDown={(event) => onKeyDown(event, index)}
                  className={cn(
                    'inline-flex items-center gap-2 rounded-md border px-3.5 py-2 text-sm font-medium transition-colors duration-150',
                    selected
                      ? 'border-primary bg-primary-subtle text-primary-strong'
                      : 'border-border bg-bg text-muted hover:bg-surface-sunken hover:text-ink',
                  )}
                >
                  <Icon size={16} aria-hidden="true" />
                  {vertical.label}
                </button>
              )
            })}
          </div>

          <div
            role="tabpanel"
            id={`solutions-panel-${current.id}`}
            aria-labelledby={`solutions-tab-${current.id}`}
            className="mt-6"
          >
            <Surface className="grid gap-8 p-6 sm:p-8 md:grid-cols-2">
              <div>
                <span className="inline-flex size-11 items-center justify-center rounded-md bg-primary-subtle text-primary-strong">
                  <current.icon size={22} aria-hidden="true" />
                </span>
                <h3 className="mt-4 text-xl font-semibold text-ink-strong">{current.headline}</h3>
                <p className="mt-3 max-w-md text-sm leading-relaxed text-muted">{current.blurb}</p>
              </div>

              <ul className="flex flex-col justify-center gap-3.5">
                {current.points.map((point) => (
                  <li key={point} className="flex items-start gap-3 text-sm text-ink">
                    <span
                      className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-success-subtle text-success-strong"
                      aria-hidden="true"
                    >
                      <Check size={13} strokeWidth={3} />
                    </span>
                    {point}
                  </li>
                ))}
              </ul>
            </Surface>
          </div>
        </Reveal>
      </Container>
    </section>
  )
}

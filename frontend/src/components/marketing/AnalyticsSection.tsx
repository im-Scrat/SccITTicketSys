import { Download, LayoutDashboard, Table2, Users } from 'lucide-react'
import { Container } from '@/components/ui/Container'
import { Reveal } from '@/components/ui/Reveal'
import { SectionHeading } from '@/components/ui/SectionHeading'
import { FeatureList, type FeatureItem } from './FeatureList'
import { AnalyticsVisual } from './visuals/AnalyticsVisual'

const items: FeatureItem[] = [
  {
    icon: LayoutDashboard,
    title: 'Role-aware dashboards',
    body: 'Administrators see cross-organization operations; technicians see their queue and workload; requesters see their own tickets.',
  },
  {
    icon: Users,
    title: 'The KPIs that matter',
    body: 'Backlog, SLA compliance and breaches, MTTR and first response, technician workload, assets by status, and low stock.',
  },
  {
    icon: Table2,
    title: 'Accessible charts',
    body: 'Every chart carries labels, non-color encoding, and a data-table fallback — legible in light and dark alike.',
  },
  {
    icon: Download,
    title: 'Export what you filter',
    body: 'Reports respect the active filters and export to CSV and PDF, computed from authoritative history tables.',
  },
]

export function AnalyticsSection() {
  return (
    <section
      id="analytics"
      className="scroll-mt-20 border-y border-border bg-surface py-20 lg:py-28"
    >
      <Container>
        <div className="grid items-center gap-12 lg:grid-cols-2 lg:gap-16">
          <Reveal>
            <AnalyticsVisual />
          </Reveal>

          <Reveal delay={100}>
            <SectionHeading
              title="The operational picture, at a glance."
              lead="Dashboards and reports built from the system’s own history — so the numbers a manager reports up are the same ones a technician works from."
            />
            <FeatureList items={items} columns={2} className="mt-8" />
          </Reveal>
        </div>
      </Container>
    </section>
  )
}

import { History, ListChecks, ScanLine, Smartphone } from 'lucide-react'
import { Container } from '@/components/ui/Container'
import { Reveal } from '@/components/ui/Reveal'
import { SectionHeading } from '@/components/ui/SectionHeading'
import { FeatureList, type FeatureItem } from './FeatureList'
import { QrAssetVisual } from './visuals/QrAssetVisual'

const items: FeatureItem[] = [
  {
    icon: Smartphone,
    title: 'Scan with any phone',
    body: 'Standard mobile-browser cameras resolve a code to its live asset — no native app to install or maintain.',
  },
  {
    icon: ListChecks,
    title: 'Deterministic results',
    body: 'Every scan classifies cleanly as success, invalid, mismatch, or expired — never an ambiguous outcome.',
  },
  {
    icon: History,
    title: 'A logged chain of custody',
    body: 'Each scan records the result, the scanner, IP, and optional location; regenerate or revoke without losing history.',
  },
  {
    icon: ScanLine,
    title: 'Scan straight into maintenance',
    body: 'A scan on the floor can start or attach a maintenance record for the resolved asset on the spot.',
  },
]

export function QrSection() {
  return (
    <section id="tracking" className="scroll-mt-20 py-20 lg:py-28">
      <Container>
        <div className="grid items-center gap-12 lg:grid-cols-2 lg:gap-16">
          <Reveal>
            <SectionHeading
              title="One scan, the whole asset."
              lead="Every asset carries a QR code bound to exactly one target. Scan it to pull up the live record, verify it’s where it should be, and act — from any phone on the floor."
            />
            <FeatureList items={items} columns={2} className="mt-8" />
          </Reveal>

          <Reveal delay={100}>
            <QrAssetVisual />
          </Reveal>
        </div>
      </Container>
    </section>
  )
}

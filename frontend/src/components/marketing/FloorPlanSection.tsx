import { Keyboard, MousePointerClick, Radio, SlidersHorizontal } from 'lucide-react'
import { Container } from '@/components/ui/Container'
import { Reveal } from '@/components/ui/Reveal'
import { SectionHeading } from '@/components/ui/SectionHeading'
import { FeatureList, type FeatureItem } from './FeatureList'
import { FloorPlanVisual } from './visuals/FloorPlanVisual'

const items: FeatureItem[] = [
  {
    icon: SlidersHorizontal,
    title: 'Place assets on a real layout',
    body: 'Drag-and-drop, snap-to-grid positioning of every PC on per-room canvases, versioned over time.',
  },
  {
    icon: MousePointerClick,
    title: 'Status you can read at a glance',
    body: 'Icons colored by the universal status palette — with a shape and label so meaning survives color-blindness.',
  },
  {
    icon: Radio,
    title: 'Live, without a refresh',
    body: 'Position and status changes reflect to other viewers in real time over Laravel Reverb.',
  },
  {
    icon: Keyboard,
    title: 'Accessible by construction',
    body: 'A keyboard, non-drag alternative for placement keeps the whole map operable without a mouse.',
  },
]

export function FloorPlanSection() {
  return (
    <section
      id="floor-plan"
      className="scroll-mt-20 border-t border-border bg-surface py-20 lg:py-28"
    >
      <Container>
        <div className="grid items-center gap-12 lg:grid-cols-2 lg:gap-16">
          <Reveal>
            <FloorPlanVisual />
          </Reveal>

          <Reveal delay={100}>
            <SectionHeading
              title="See the whole floor, not just a list."
              lead="Interactive floor plans turn a spreadsheet of assets into a map of the room — where every machine is, what it’s doing, and what needs attention."
            />
            <FeatureList items={items} columns={2} className="mt-8" />
          </Reveal>
        </div>
      </Container>
    </section>
  )
}

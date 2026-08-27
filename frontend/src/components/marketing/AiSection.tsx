import type { LucideIcon } from 'lucide-react'
import { EyeOff, ShieldCheck, Sparkles, ToggleRight } from 'lucide-react'
import { Container } from '@/components/ui/Container'
import { Reveal } from '@/components/ui/Reveal'
import { SectionHeading } from '@/components/ui/SectionHeading'
import { AiTriageVisual } from './visuals/AiTriageVisual'

const governance: { icon: LucideIcon; title: string; body: string }[] = [
  {
    icon: Sparkles,
    title: 'Grounded in your data',
    body: 'Retrieval-augmented over your own tickets, maintenance, and articles — answers cite the records they came from.',
  },
  {
    icon: ShieldCheck,
    title: 'Advisory, never autonomous',
    body: 'AI never closes tickets, disposes assets, or changes permissions. Every suggestion waits for a human to confirm.',
  },
  {
    icon: EyeOff,
    title: 'Data-minimized',
    body: 'Only the minimum needed leaves the platform, with personal data redacted from prompts and embeddings.',
  },
  {
    icon: ToggleRight,
    title: 'Off by default',
    body: 'Predictions, learning, and auto-drafted articles ship disabled. Enabling them is always an explicit choice.',
  },
]

export function AiSection() {
  return (
    <section id="intelligence" className="scroll-mt-20 py-20 lg:py-28">
      <Container>
        <div className="grid items-center gap-12 lg:grid-cols-2 lg:gap-16">
          <Reveal>
            <SectionHeading
              title="Intelligence that stays on a leash."
              lead="Gemini-grounded triage and a retrieval-augmented assistant, trained on your own operational data — and governed so it stays advisory, transparent, and under your control."
            />

            <ul className="mt-8 grid gap-x-8 gap-y-6 sm:grid-cols-2">
              {governance.map(({ icon: Icon, title, body }) => (
                <li key={title}>
                  <span className="inline-flex size-9 items-center justify-center rounded-md bg-surface-sunken text-primary-strong">
                    <Icon size={18} aria-hidden="true" />
                  </span>
                  <h3 className="mt-3 text-sm font-semibold text-ink-strong">{title}</h3>
                  <p className="mt-1.5 text-sm leading-relaxed text-muted">{body}</p>
                </li>
              ))}
            </ul>
          </Reveal>

          <Reveal delay={100}>
            <AiTriageVisual />
          </Reveal>
        </div>
      </Container>
    </section>
  )
}

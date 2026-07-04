import { Check, Sparkles } from 'lucide-react'
import { cn } from '@/lib/cn'
import { Badge } from '@/components/ui/Badge'

/**
 * The AI triage snapshot attached to a ticket (FR-AI-001/003): a category,
 * severity, estimate, and confidence score, plus recommended steps. Explicitly
 * labelled AI-generated (FR-AI-031) and framed as advisory — a human confirms
 * before any change (FR-AI-010/032). Presentational.
 */

const analysis = [
  { label: 'Category', value: 'Display · Projector' },
  { label: 'Severity', value: 'Medium' },
  { label: 'Est. resolution', value: '25 min' },
  { label: 'Technician', value: 'Required' },
]

const steps = [
  { text: 'Confirm the projector is powered and the input source is correct', done: true },
  { text: 'Reseat the HDMI cable at both ends; test a known-good cable', done: true },
  { text: 'Check the source device output resolution and mirroring', done: false },
]

const CONFIDENCE = 0.82

export function AiTriageVisual({ className }: { className?: string }) {
  return (
    <figure
      role="img"
      aria-label="An AI triage panel for a projector ticket: category Display, severity Medium, 25-minute estimate, technician required, 0.82 confidence, with three recommended troubleshooting steps and an advisory-only notice."
      className={cn('overflow-hidden rounded-lg border border-border bg-surface', className)}
    >
      <div className="flex items-center justify-between border-b border-border px-4 py-2.5">
        <span className="flex items-center gap-1.5 text-sm font-medium text-ink-strong">
          <span className="slashed-zero font-mono text-[11px] text-muted">#4821</span>
          Projector — no signal
        </span>
      </div>

      <div className="p-4">
        <div className="mb-3 flex items-center gap-2">
          <span className="flex size-6 items-center justify-center rounded-sm bg-primary-subtle text-primary-strong">
            <Sparkles size={14} aria-hidden="true" />
          </span>
          <span className="text-[13px] font-semibold text-ink-strong">AI analysis</span>
          <Badge tone="primary" className="ml-auto">
            AI-generated
          </Badge>
        </div>

        <dl className="grid grid-cols-2 gap-x-4 gap-y-2.5">
          {analysis.map((item) => (
            <div key={item.label} className="min-w-0">
              <dt className="text-[11px] text-muted">{item.label}</dt>
              <dd className="truncate text-[13px] font-medium text-ink">{item.value}</dd>
            </div>
          ))}
        </dl>

        <div className="mt-3.5">
          <div className="mb-1 flex items-center justify-between text-[11px]">
            <span className="text-muted">Confidence</span>
            <span className="tnum font-medium text-ink">{CONFIDENCE.toFixed(2)}</span>
          </div>
          <div
            className="h-1.5 overflow-hidden rounded-full bg-surface-sunken"
            role="meter"
            aria-valuenow={CONFIDENCE}
            aria-valuemin={0}
            aria-valuemax={1}
            aria-label="AI confidence"
          >
            <div
              className="h-full rounded-full bg-primary"
              style={{ width: `${CONFIDENCE * 100}%` }}
            />
          </div>
        </div>

        <ul className="mt-4 space-y-2">
          {steps.map((step) => (
            <li key={step.text} className="flex items-start gap-2.5 text-[12.5px]">
              <span
                className={cn(
                  'mt-px flex size-4 shrink-0 items-center justify-center rounded-[4px] border',
                  step.done
                    ? 'border-success bg-success-subtle text-success-strong'
                    : 'border-control-border text-transparent',
                )}
                aria-hidden="true"
              >
                <Check size={11} strokeWidth={3} />
              </span>
              <span className={cn(step.done ? 'text-muted line-through' : 'text-ink')}>
                {step.text}
              </span>
            </li>
          ))}
        </ul>
      </div>

      <p className="border-t border-border bg-surface-sunken px-4 py-2.5 text-[11.5px] text-muted">
        Advisory only — a technician reviews and confirms before any change.
      </p>
    </figure>
  )
}

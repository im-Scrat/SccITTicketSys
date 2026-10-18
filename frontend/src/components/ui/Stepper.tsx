import { Check } from 'lucide-react'
import { cn } from '@/lib/cn'

export interface StepperStep {
  id: string
  label: string
}

interface StepperProps {
  steps: StepperStep[]
  current: string
  /**
   * Steps the user may go back to. Only earlier steps are ever offered — a
   * wizard cannot be skipped forward from here.
   */
  onStepSelect?: (id: string) => void
  /** Steps that cannot be revisited (e.g. everything before "Importing"). */
  lockedSteps?: string[]
  /** Names the list ("Import steps"). */
  label: string
  className?: string
}

/**
 * Where a multi-step flow is, and the way back. Completed steps carry a check
 * and the current one is bold and `aria-current="step"`, so state never rests
 * on colour alone. Below `sm` the row collapses to "Step 3 of 6 · Review and
 * fix" — six markers do not fit a phone without shrinking text below 16px.
 */
export function Stepper({
  steps,
  current,
  onStepSelect,
  lockedSteps = [],
  label,
  className,
}: StepperProps) {
  const currentIndex = Math.max(
    steps.findIndex((step) => step.id === current),
    0,
  )
  const currentStep = steps[currentIndex]

  return (
    <nav aria-label={label} className={className}>
      <p className="text-sm text-ink sm:hidden">
        <span className="font-semibold">
          Step {currentIndex + 1} of {steps.length}
        </span>
        {currentStep && <span className="text-muted"> · {currentStep.label}</span>}
      </p>

      <ol className="hidden flex-wrap items-center gap-x-2 gap-y-3 sm:flex">
        {steps.map((step, index) => {
          const state =
            index < currentIndex ? 'complete' : index === currentIndex ? 'current' : 'upcoming'
          const selectable =
            state === 'complete' && onStepSelect !== undefined && !lockedSteps.includes(step.id)

          const marker = (
            <span
              aria-hidden="true"
              className={cn(
                'flex size-8 shrink-0 items-center justify-center rounded-full border-2 text-xs font-semibold tabular-nums',
                state === 'complete' && 'border-primary bg-primary text-on-primary',
                state === 'current' && 'border-primary bg-surface text-primary',
                state === 'upcoming' && 'border-border bg-surface text-muted',
              )}
            >
              {state === 'complete' ? <Check size={16} strokeWidth={3} /> : index + 1}
            </span>
          )

          const text = (
            <span
              className={cn(
                'text-sm',
                state === 'current' ? 'font-semibold text-ink-strong' : 'text-muted',
              )}
            >
              {step.label}
              {state === 'complete' && <span className="sr-only">, completed</span>}
            </span>
          )

          return (
            <li key={step.id} className="flex items-center gap-2">
              {index > 0 && (
                <span
                  aria-hidden="true"
                  className={cn(
                    'h-0.5 w-6 rounded-full',
                    index <= currentIndex ? 'bg-primary' : 'bg-border',
                  )}
                />
              )}
              {selectable ? (
                <button
                  type="button"
                  onClick={() => onStepSelect(step.id)}
                  className="flex items-center gap-2 rounded-sm py-1 pr-1 hover:[&>span:last-child]:text-ink"
                >
                  {marker}
                  {text}
                </button>
              ) : (
                <span
                  className="flex items-center gap-2 py-1"
                  aria-current={state === 'current' ? 'step' : undefined}
                >
                  {marker}
                  {text}
                </span>
              )}
            </li>
          )
        })}
      </ol>
    </nav>
  )
}

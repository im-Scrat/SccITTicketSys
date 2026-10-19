import { type Ref, useId, useMemo } from 'react'
import { cn } from '@/lib/cn'
import { PRIORITY_GUIDES } from '../priorityGuides'
import type { PriorityOption } from '../types'
import { PriorityDot } from './TicketBadges'

interface PriorityChoiceProps {
  priorities: PriorityOption[]
  /** A priority slug, or '' for "let the IT team decide". */
  value: string
  onChange: (value: string) => void
  onBlur?: () => void
  name?: string
  error?: string
  /** Staff file on someone's behalf; their "no choice" means something else. */
  staff?: boolean
  disabled?: boolean
  /**
   * Handed to the checked radio, so react-hook-form can move focus here when
   * the server refuses the choice.
   */
  inputRef?: Ref<HTMLInputElement>
}

const OPTION =
  'flex min-h-15 cursor-pointer items-start gap-3 border-2 border-transparent bg-surface px-4 py-3 transition-colors duration-150 [transition-timing-function:var(--ease-standard)] hover:bg-surface-sunken has-[:checked]:border-primary has-[:disabled]:cursor-not-allowed has-[:disabled]:hover:bg-surface'

/**
 * "How urgent is it?" — the reporter's own priority (SDD DD-75).
 *
 * **Not choosing is the default, and it is a real answer.** "Not sure — let the
 * IT team decide" comes first and is preselected: the category's default then
 * applies, exactly as before reporters could choose. A teacher who does choose
 * makes the second-weakest claim there is — a room's minimum or the AI may
 * still raise it, and IT staff may change it — which is why the guides describe
 * the effect on work in neutral words rather than inviting everyone to pick
 * Urgent.
 *
 * Native radios in a `fieldset` exposed as a radiogroup, so arrow keys move the
 * choice and a screen reader announces the question with each option. The
 * levels run least to most urgent — the server lists them most urgent first,
 * which suits a staff dropdown but reads as alarm on a reporter's form.
 *
 * Selection is shown by the radio's dot and a primary border, never a wash: the
 * preselected "Not sure" is not a choice anyone made, and a tinted block would
 * present it as one. Urgent's red dot is the only red here (Reserved-Red: it
 * marks the critical level); the selected state itself is always primary.
 */
export function PriorityChoice({
  priorities,
  value,
  onChange,
  onBlur,
  name = 'priority',
  error,
  staff = false,
  disabled = false,
  inputRef,
}: PriorityChoiceProps) {
  const id = useId()
  const legendId = `${id}-legend`
  const errorId = `${id}-error`

  const ascending = useMemo(() => [...priorities].sort((a, b) => a.level - b.level), [priorities])

  const options = [
    {
      value: '',
      label: staff
        ? 'Use the category default, raised to the room’s minimum'
        : 'Not sure — let the IT team decide',
      guide: staff ? null : 'They set it once they’ve read your report.',
      slug: null as string | null,
    },
    ...ascending.map((priority) => ({
      value: priority.value,
      label: priority.label,
      guide: PRIORITY_GUIDES[priority.value] ?? null,
      slug: priority.value as string | null,
    })),
  ]

  // Focus goes to the checked radio, or the first when nothing matches.
  const focusTarget = options.some((option) => option.value === value) ? value : ''

  return (
    <fieldset
      role="radiogroup"
      aria-labelledby={legendId}
      aria-describedby={error ? errorId : undefined}
      className="flex min-w-0 flex-col gap-3"
    >
      {/* A rendered legend sits outside the fieldset's flex box: `gap` does not reach it. */}
      <legend id={legendId} className="mb-3 text-sm font-semibold text-ink">
        How urgent is it?
      </legend>

      <div className="grid gap-px overflow-hidden rounded-md border border-border bg-border sm:grid-cols-2">
        {options.map((option, index) => {
          const optionId = `${id}-${index}`
          const guideId = option.guide ? `${optionId}-guide` : undefined

          return (
            <label
              key={option.value || 'none'}
              className={cn(OPTION, index === 0 && 'sm:col-span-2')}
            >
              <input
                ref={option.value === focusTarget ? inputRef : undefined}
                type="radio"
                name={name}
                value={option.value}
                checked={value === option.value}
                onChange={() => onChange(option.value)}
                onBlur={onBlur}
                disabled={disabled}
                aria-labelledby={`${optionId}-label`}
                aria-describedby={guideId}
                className="mt-1 size-5 shrink-0 accent-primary"
              />
              <span className="flex min-w-0 flex-col gap-0.5">
                <span
                  id={`${optionId}-label`}
                  className="flex items-center gap-2.5 text-sm font-semibold text-ink"
                >
                  {option.slug && <PriorityDot slug={option.slug} />}
                  {option.label}
                </span>
                {option.guide && (
                  <span id={guideId} className="text-xs text-muted">
                    {option.guide}
                  </span>
                )}
              </span>
            </label>
          )
        })}
      </div>

      {error && (
        <p id={errorId} role="alert" className="text-sm font-medium text-danger-strong">
          {error}
        </p>
      )}
    </fieldset>
  )
}

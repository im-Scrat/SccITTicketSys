import { cloneElement, isValidElement, type ReactElement, type ReactNode, useId } from 'react'

interface FieldProps {
  label: string
  /** A single form control; it receives id / aria-invalid / aria-describedby. */
  children: ReactNode
  error?: string
  hint?: string
  required?: boolean
}

type InjectedProps = {
  id: string
  'aria-invalid'?: boolean
  'aria-describedby'?: string
}

/**
 * Label + control + message wrapper (DESIGN.md: label above the field). Generates
 * a stable id, wires the control's `aria-invalid`/`aria-describedby`, and shows
 * an error (announced) or a hint. Error is never color-only — it is real text.
 */
export function Field({ label, children, error, hint, required }: FieldProps) {
  const id = useId()
  const errorId = `${id}-error`
  const hintId = `${id}-hint`
  const describedBy = error ? errorId : hint ? hintId : undefined

  const control = isValidElement(children)
    ? cloneElement(children as ReactElement<InjectedProps>, {
        id,
        'aria-invalid': error ? true : undefined,
        'aria-describedby': describedBy,
      })
    : children

  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="text-xs font-medium text-ink">
        {label}
        {required && (
          <span className="text-danger-strong" aria-hidden="true">
            {' '}
            *
          </span>
        )}
      </label>
      {control}
      {error ? (
        <p id={errorId} className="text-xs text-danger-strong" role="alert">
          {error}
        </p>
      ) : hint ? (
        <p id={hintId} className="text-xs text-muted">
          {hint}
        </p>
      ) : null}
    </div>
  )
}

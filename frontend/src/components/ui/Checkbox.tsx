import { forwardRef, type InputHTMLAttributes, useId } from 'react'
import { cn } from '@/lib/cn'

interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
  label: string
  /**
   * Keep the label for assistive technology but hide it visually — for row
   * selection in a table, where the column header already says what the box is
   * for and repeating it in every row would be noise. The label is still
   * *present*, never dropped: a bare checkbox is unusable with a screen reader.
   */
  labelHidden?: boolean
}

/**
 * Labeled checkbox (e.g. "Remember me"). Native input tinted with the brand
 * accent; the whole label is the click/hit target and the global focus ring
 * applies to the box.
 */
export const Checkbox = forwardRef<HTMLInputElement, CheckboxProps>(function Checkbox(
  { label, labelHidden, id, className, ...props },
  ref,
) {
  const generatedId = useId()
  const inputId = id ?? generatedId

  return (
    <label
      htmlFor={inputId}
      className="inline-flex cursor-pointer items-center gap-2 text-sm text-ink select-none"
    >
      <input
        ref={ref}
        id={inputId}
        type="checkbox"
        className={cn('size-6 rounded-sm border-control-border accent-primary', className)}
        {...props}
      />
      <span className={cn(labelHidden && 'sr-only')}>{label}</span>
    </label>
  )
})

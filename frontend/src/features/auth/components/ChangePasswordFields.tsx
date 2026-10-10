import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { PasswordInput } from '@/components/ui/PasswordInput'
import { useChangePassword } from '../hooks/useAuthMutations'
import { applyServerErrors, getErrorMessage } from '../lib/serverErrors'
import { type ChangePasswordForm, changePasswordSchema } from '../schemas'

interface ChangePasswordFieldsProps {
  /** Called after the server accepted the new password. */
  onChanged?: () => void
  submitLabel?: string
  /** Stretch the submit button (the standalone auth-card layout). */
  fullWidth?: boolean
}

/**
 * The change-password form, shared by the account page and the forced-change
 * screen so the two cannot drift apart in validation or error handling.
 * Field-level server errors (a wrong current password, a weak or reused new
 * one) land on their fields; anything else is one form-level alert.
 */
export function ChangePasswordFields({
  onChanged,
  submitLabel = 'Update password',
  fullWidth = false,
}: ChangePasswordFieldsProps) {
  const [formError, setFormError] = useState<string | null>(null)
  const changeMutation = useChangePassword()

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ChangePasswordForm>({
    resolver: zodResolver(changePasswordSchema),
    defaultValues: { current_password: '', password: '', password_confirmation: '' },
  })

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      await changeMutation.mutateAsync(values)
      reset()
      onChanged?.()
    } catch (error) {
      if (!applyServerErrors(error, setError)) {
        setFormError(
          getErrorMessage(error, 'Your password could not be changed. Please try again.'),
        )
      }
    }
  })

  return (
    <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
      {formError && <Alert tone="error">{formError}</Alert>}

      <Field label="Current password" error={errors.current_password?.message} required>
        <PasswordInput autoComplete="current-password" {...register('current_password')} />
      </Field>
      <Field
        label="New password"
        error={errors.password?.message}
        hint="At least 10 characters, using 3 of: lowercase, uppercase, number, symbol."
        required
      >
        <PasswordInput autoComplete="new-password" {...register('password')} />
      </Field>
      <Field label="Confirm new password" error={errors.password_confirmation?.message} required>
        <PasswordInput autoComplete="new-password" {...register('password_confirmation')} />
      </Field>

      <div>
        <Button
          type="submit"
          variant="primary"
          size={fullWidth ? 'lg' : undefined}
          loading={isSubmitting}
          className={fullWidth ? 'w-full' : undefined}
        >
          {submitLabel}
        </Button>
      </div>
    </form>
  )
}

import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useSearchParams } from 'react-router-dom'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { Alert } from '@/components/ui/Alert'
import { Button, ButtonLink } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { PasswordInput } from '@/components/ui/PasswordInput'
import { AuthLayout } from '@/layouts/AuthLayout'
import { useResetPassword } from '../hooks/useAuthMutations'
import { applyServerErrors, getErrorMessage } from '../lib/serverErrors'
import { type ResetPasswordForm, resetPasswordSchema } from '../schemas'

export default function ResetPasswordPage() {
  useDocumentMeta({ title: 'Set a new password' })

  const [searchParams] = useSearchParams()
  const token = searchParams.get('token') ?? ''
  const email = searchParams.get('email') ?? ''

  const [done, setDone] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const resetMutation = useResetPassword()

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ResetPasswordForm>({
    resolver: zodResolver(resetPasswordSchema),
    defaultValues: { password: '', password_confirmation: '' },
  })

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      await resetMutation.mutateAsync({
        token,
        email,
        password: values.password,
        password_confirmation: values.password_confirmation,
      })
      setDone(true)
    } catch (error) {
      if (!applyServerErrors(error, setError)) {
        setFormError(
          getErrorMessage(error, 'We couldn’t reset your password. The link may have expired.'),
        )
      }
    }
  })

  if (!token || !email) {
    return (
      <AuthLayout title="Invalid reset link">
        <Alert tone="error">
          This password reset link is invalid or incomplete. Please request a new one.
        </Alert>
        <ButtonLink to="/forgot-password" variant="secondary" size="lg" className="mt-4 w-full">
          Request a new link
        </ButtonLink>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout
      title="Set a new password"
      subtitle={`Choose a new password for ${email}.`}
      footer={
        <Link to="/sign-in" className="font-medium text-primary-strong hover:underline">
          Back to sign in
        </Link>
      }
    >
      {done ? (
        <>
          <Alert tone="success" title="Password updated">
            Your password has been reset. You can now sign in with your new password.
          </Alert>
          <ButtonLink to="/sign-in" variant="primary" size="lg" className="mt-4 w-full">
            Go to sign in
          </ButtonLink>
        </>
      ) : (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
          {formError && <Alert tone="error">{formError}</Alert>}

          <Field
            label="New password"
            error={errors.password?.message}
            hint="At least 10 characters, using 3 of: lowercase, uppercase, number, symbol."
            required
          >
            <PasswordInput autoComplete="new-password" {...register('password')} />
          </Field>

          <Field
            label="Confirm new password"
            error={errors.password_confirmation?.message}
            required
          >
            <PasswordInput autoComplete="new-password" {...register('password_confirmation')} />
          </Field>

          <Button
            type="submit"
            variant="primary"
            size="lg"
            loading={isSubmitting}
            className="w-full"
          >
            Reset password
          </Button>
        </form>
      )}
    </AuthLayout>
  )
}

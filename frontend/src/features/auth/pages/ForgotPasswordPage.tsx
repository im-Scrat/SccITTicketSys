import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router-dom'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { AuthLayout } from '@/layouts/AuthLayout'
import { useForgotPassword } from '../hooks/useAuthMutations'
import { getErrorMessage } from '../lib/serverErrors'
import { type ForgotPasswordForm, forgotPasswordSchema } from '../schemas'

export default function ForgotPasswordPage() {
  useDocumentMeta({ title: 'Forgot password' })

  const [sent, setSent] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const forgotMutation = useForgotPassword()

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<ForgotPasswordForm>({
    resolver: zodResolver(forgotPasswordSchema),
    defaultValues: { email: '' },
  })

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      await forgotMutation.mutateAsync(values.email)
      setSent(true)
    } catch (error) {
      setFormError(getErrorMessage(error))
    }
  })

  return (
    <AuthLayout
      title="Reset your password"
      subtitle="Enter your email and we’ll send you a link to set a new password."
      footer={
        <Link to="/sign-in" className="font-medium text-primary-strong hover:underline">
          Back to sign in
        </Link>
      }
    >
      {sent ? (
        <Alert tone="success" title="Check your email">
          If an account exists for that address, a password reset link is on its way.
        </Alert>
      ) : (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
          {formError && <Alert tone="error">{formError}</Alert>}

          <Field label="Email" error={errors.email?.message} required>
            <Input
              type="email"
              autoComplete="email"
              placeholder="you@school.edu"
              {...register('email')}
            />
          </Field>

          <Button
            type="submit"
            variant="primary"
            size="lg"
            loading={isSubmitting}
            className="w-full"
          >
            Send reset link
          </Button>
        </form>
      )}
    </AuthLayout>
  )
}

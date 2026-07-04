import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Checkbox } from '@/components/ui/Checkbox'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { PasswordInput } from '@/components/ui/PasswordInput'
import { AuthLayout } from '@/layouts/AuthLayout'
import { useLogin } from '../hooks/useAuthMutations'
import { applyServerErrors, getAccountStatusError, getErrorMessage } from '../lib/serverErrors'
import { type LoginForm, loginSchema } from '../schemas'

interface FromState {
  from?: { pathname?: string }
}

export default function SignInPage() {
  useDocumentMeta({ title: 'Sign in' })

  const navigate = useNavigate()
  const location = useLocation()
  const redirectTo = (location.state as FromState | null)?.from?.pathname ?? '/app'

  const [formError, setFormError] = useState<string | null>(null)
  const loginMutation = useLogin()

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<LoginForm>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: '', password: '', remember: false },
  })

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      await loginMutation.mutateAsync({
        email: values.email,
        password: values.password,
        remember: Boolean(values.remember),
      })
      navigate(redirectTo, { replace: true })
    } catch (error) {
      const status = getAccountStatusError(error)
      if (status?.code === 'pending') {
        navigate('/pending-approval', { replace: true })
        return
      }
      if (status) {
        setFormError(status.reason ? `${status.message} (${status.reason})` : status.message)
        return
      }
      if (!applyServerErrors(error, setError)) {
        setFormError(getErrorMessage(error, 'We couldn’t sign you in. Please try again.'))
      }
    }
  })

  return (
    <AuthLayout
      title="Sign in to your workspace"
      subtitle="Enter your credentials to access the SccIT operations console."
      footer={
        <>
          Need an account?{' '}
          <Link to="/register" className="font-medium text-primary-strong hover:underline">
            Request access
          </Link>
        </>
      }
    >
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

        <Field label="Password" error={errors.password?.message} required>
          <PasswordInput autoComplete="current-password" {...register('password')} />
        </Field>

        <div className="flex items-center justify-between">
          <Checkbox label="Remember me" {...register('remember')} />
          <Link
            to="/forgot-password"
            className="text-sm font-medium text-primary-strong hover:underline"
          >
            Forgot password?
          </Link>
        </div>

        <Button type="submit" variant="primary" size="lg" loading={isSubmitting} className="w-full">
          Sign in
        </Button>
      </form>
    </AuthLayout>
  )
}

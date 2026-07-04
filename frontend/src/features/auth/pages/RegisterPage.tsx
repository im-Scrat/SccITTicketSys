import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useNavigate } from 'react-router-dom'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { PasswordInput } from '@/components/ui/PasswordInput'
import { Select } from '@/components/ui/Select'
import { AuthLayout } from '@/layouts/AuthLayout'
import { useRegister } from '../hooks/useAuthMutations'
import { applyServerErrors, getErrorMessage } from '../lib/serverErrors'
import { type RegisterForm, registerSchema } from '../schemas'

export default function RegisterPage() {
  useDocumentMeta({ title: 'Request access' })

  const navigate = useNavigate()
  const [formError, setFormError] = useState<string | null>(null)
  const registerMutation = useRegister()

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<RegisterForm>({
    resolver: zodResolver(registerSchema),
    defaultValues: {
      first_name: '',
      middle_name: '',
      last_name: '',
      email: '',
      employee_number: '',
      contact_number: '',
      role: 'teacher',
      password: '',
      password_confirmation: '',
    },
  })

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      await registerMutation.mutateAsync({
        first_name: values.first_name,
        middle_name: values.middle_name || undefined,
        last_name: values.last_name,
        email: values.email,
        employee_number: values.employee_number || undefined,
        contact_number: values.contact_number || undefined,
        role: values.role,
        password: values.password,
        password_confirmation: values.password_confirmation,
      })
      navigate('/register/submitted', { replace: true })
    } catch (error) {
      if (!applyServerErrors(error, setError)) {
        setFormError(getErrorMessage(error, 'We couldn’t submit your request. Please try again.'))
      }
    }
  })

  return (
    <AuthLayout
      title="Request an account"
      subtitle="Teachers and technicians can request access. An administrator reviews every request before your account is activated."
      footer={
        <>
          Already have an account?{' '}
          <Link to="/sign-in" className="font-medium text-primary-strong hover:underline">
            Sign in
          </Link>
        </>
      }
    >
      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
        {formError && <Alert tone="error">{formError}</Alert>}

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="First name" error={errors.first_name?.message} required>
            <Input autoComplete="given-name" {...register('first_name')} />
          </Field>
          <Field label="Last name" error={errors.last_name?.message} required>
            <Input autoComplete="family-name" {...register('last_name')} />
          </Field>
        </div>

        <Field label="Email" error={errors.email?.message} required>
          <Input
            type="email"
            autoComplete="email"
            placeholder="you@school.edu"
            {...register('email')}
          />
        </Field>

        <Field label="I am a" error={errors.role?.message} required>
          <Select {...register('role')}>
            <option value="teacher">Teacher (requester)</option>
            <option value="technician">Technician</option>
          </Select>
        </Field>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Employee number" error={errors.employee_number?.message} hint="Optional">
            <Input {...register('employee_number')} />
          </Field>
          <Field label="Contact number" error={errors.contact_number?.message} hint="Optional">
            <Input type="tel" autoComplete="tel" {...register('contact_number')} />
          </Field>
        </div>

        <Field
          label="Password"
          error={errors.password?.message}
          hint="At least 10 characters, using 3 of: lowercase, uppercase, number, symbol."
          required
        >
          <PasswordInput autoComplete="new-password" {...register('password')} />
        </Field>

        <Field label="Confirm password" error={errors.password_confirmation?.message} required>
          <PasswordInput autoComplete="new-password" {...register('password_confirmation')} />
        </Field>

        <Button type="submit" variant="primary" size="lg" loading={isSubmitting} className="w-full">
          Submit request
        </Button>
      </form>
    </AuthLayout>
  )
}

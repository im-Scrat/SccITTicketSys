import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { PasswordInput } from '@/components/ui/PasswordInput'
import { Surface } from '@/components/ui/Surface'
import { PreferenceMatrix } from '@/features/notifications/components/PreferenceMatrix'
import { useAuth } from '../hooks/useAuth'
import { useChangePassword, useUpdateProfile } from '../hooks/useAuthMutations'
import { applyServerErrors, getErrorMessage } from '../lib/serverErrors'
import {
  type ChangeNameForm,
  changeNameSchema,
  type ChangePasswordForm,
  changePasswordSchema,
} from '../schemas'

function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export default function WorkspaceHomePage() {
  useDocumentMeta({ title: 'Workspace' })
  const { user } = useAuth()

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-[-0.02em] text-ink-strong">
          Welcome, {user?.first_name}
        </h1>
        <p className="mt-1 text-sm text-muted">
          You’re signed in to the SccIT operations console. Modules will appear here as they’re
          rolled out.
        </p>
      </div>

      <Surface className="p-5">
        <h2 className="text-sm font-semibold text-ink-strong">Your account</h2>
        <dl className="mt-4 grid grid-cols-[auto_1fr] gap-x-6 gap-y-2.5 text-sm">
          <dt className="text-muted">Name</dt>
          <dd className="text-ink">{user?.name}</dd>
          <dt className="text-muted">Email</dt>
          <dd className="text-ink">{user?.email}</dd>
          <dt className="text-muted">Role</dt>
          <dd className="text-ink capitalize">{user?.role.name}</dd>
          <dt className="text-muted">Account status</dt>
          <dd className="text-ink capitalize">{user?.status}</dd>
          <dt className="text-muted">Last login</dt>
          <dd className="text-ink">{formatDateTime(user?.last_login_at)}</dd>
        </dl>
      </Surface>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <ChangeNameCard />
        <ChangePasswordCard />
      </div>

      {/*
        Notification preferences live here rather than on the notification
        centre (Client decision, Q5): this is where a user already comes to
        change things about themselves, and the page is ungated for exactly the
        same reason the centre is — everyone has an account, and everyone has
        notifications (FR-NOT-002).
      */}
      <PreferenceMatrix />
    </div>
  )
}

function ChangeNameCard() {
  const { user } = useAuth()
  const [success, setSuccess] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const updateProfile = useUpdateProfile()

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ChangeNameForm>({
    resolver: zodResolver(changeNameSchema),
    values: {
      first_name: user?.first_name ?? '',
      middle_name: user?.middle_name ?? '',
      last_name: user?.last_name ?? '',
    },
  })

  const onSubmit = handleSubmit(async (formValues) => {
    setFormError(null)
    setSuccess(false)
    try {
      await updateProfile.mutateAsync({
        ...formValues,
        middle_name: formValues.middle_name || undefined,
      })
      setSuccess(true)
    } catch (error) {
      if (!applyServerErrors(error, setError)) {
        setFormError(getErrorMessage(error))
      }
    }
  })

  return (
    <Surface className="p-5">
      <h2 className="text-sm font-semibold text-ink-strong">Change name</h2>
      <p className="mt-1 text-xs text-muted">Update the name shown across the console.</p>

      <form onSubmit={onSubmit} noValidate className="mt-4 flex flex-col gap-4">
        {success && <Alert tone="success">Your name has been updated.</Alert>}
        {formError && <Alert tone="error">{formError}</Alert>}

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="First name" error={errors.first_name?.message} required>
            <Input autoComplete="given-name" {...register('first_name')} />
          </Field>
          <Field label="Last name" error={errors.last_name?.message} required>
            <Input autoComplete="family-name" {...register('last_name')} />
          </Field>
        </div>
        <Field label="Middle name" error={errors.middle_name?.message}>
          <Input autoComplete="additional-name" {...register('middle_name')} />
        </Field>

        <div>
          <Button type="submit" variant="primary" loading={isSubmitting}>
            Save name
          </Button>
        </div>
      </form>
    </Surface>
  )
}

function ChangePasswordCard() {
  const [success, setSuccess] = useState(false)
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
    setSuccess(false)
    try {
      await changeMutation.mutateAsync(values)
      reset()
      setSuccess(true)
    } catch (error) {
      if (!applyServerErrors(error, setError)) {
        setFormError(getErrorMessage(error))
      }
    }
  })

  return (
    <Surface className="p-5">
      <h2 className="text-sm font-semibold text-ink-strong">Change password</h2>
      <p className="mt-1 text-xs text-muted">
        Changing your password signs out your other active sessions.
      </p>

      <form onSubmit={onSubmit} noValidate className="mt-4 flex flex-col gap-4">
        {success && <Alert tone="success">Your password has been changed.</Alert>}
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
          <Button type="submit" variant="primary" loading={isSubmitting}>
            Update password
          </Button>
        </div>
      </form>
    </Surface>
  )
}

import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { Alert } from '@/components/ui/Alert'
import { Badge } from '@/components/ui/Badge'
import { Button } from '@/components/ui/Button'
import { Field } from '@/components/ui/Field'
import { PasswordInput } from '@/components/ui/PasswordInput'
import { Surface } from '@/components/ui/Surface'
import { useAuth } from '../hooks/useAuth'
import { useChangePassword } from '../hooks/useAuthMutations'
import { applyServerErrors, getErrorMessage } from '../lib/serverErrors'
import { type ChangePasswordForm, changePasswordSchema } from '../schemas'

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

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Surface className="p-5">
          <h2 className="text-sm font-semibold text-ink-strong">Your account</h2>
          <dl className="mt-4 grid grid-cols-[auto_1fr] gap-x-6 gap-y-2.5 text-sm">
            <dt className="text-muted">Name</dt>
            <dd className="text-ink">{user?.name}</dd>
            <dt className="text-muted">Email</dt>
            <dd className="text-ink">{user?.email}</dd>
            <dt className="text-muted">Role</dt>
            <dd className="text-ink capitalize">{user?.role.name}</dd>
            <dt className="text-muted">Status</dt>
            <dd className="text-ink capitalize">{user?.status}</dd>
          </dl>
        </Surface>

        <Surface className="p-5">
          <h2 className="text-sm font-semibold text-ink-strong">Your permissions</h2>
          <p className="mt-1 text-xs text-muted">
            Effective access ({user?.permissions.length ?? 0}). Enforced by the server on every
            request.
          </p>
          <div className="mt-3 flex max-h-48 flex-wrap gap-1.5 overflow-y-auto">
            {user?.permissions.map((permission) => (
              <Badge key={permission} tone="neutral">
                <span className="font-mono text-xs">{permission}</span>
              </Badge>
            ))}
          </div>
        </Surface>
      </div>

      <ChangePasswordCard />
    </div>
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
    <Surface className="max-w-xl p-5">
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

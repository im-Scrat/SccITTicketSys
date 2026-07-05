import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { Alert, Button, Drawer, Field, Input } from '@/components/ui'
import { applyServerErrors, getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useUpdateUser } from '../hooks/mutations'
import { type EditUserForm, editUserSchema } from '../schemas'
import type { UserDetail } from '../types'

interface EditUserDrawerProps {
  open: boolean
  onClose: () => void
  user: UserDetail
}

/** Edit a user's profile fields (SRS FR-USER-001). Role/status/password have
 * their own dedicated controls on the detail page. */
export function EditUserDrawer({ open, onClose, user }: EditUserDrawerProps) {
  const update = useUpdateUser(user.id)
  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<EditUserForm>({
    resolver: zodResolver(editUserSchema),
  })

  useEffect(() => {
    if (open) {
      reset({
        first_name: user.first_name,
        middle_name: user.middle_name ?? '',
        last_name: user.last_name,
        email: user.email,
        employee_number: user.employee_number ?? '',
        contact_number: user.contact_number ?? '',
      })
    }
  }, [open, user, reset])

  const submit = handleSubmit(async (values) => {
    try {
      await update.mutateAsync({
        ...values,
        middle_name: values.middle_name || undefined,
        employee_number: values.employee_number || undefined,
        contact_number: values.contact_number || undefined,
      })
      onClose()
    } catch (error) {
      if (!applyServerErrors(error, setError)) {
        setError('root', { message: getErrorMessage(error) })
      }
    }
  })

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title="Edit profile"
      description={user.name}
      footer={
        <>
          <Button variant="ghost" size="sm" onClick={onClose}>
            Cancel
          </Button>
          <Button size="sm" loading={update.isPending} onClick={() => void submit()}>
            Save changes
          </Button>
        </>
      }
    >
      <form onSubmit={submit} className="flex flex-col gap-4">
        {errors.root && <Alert tone="error">{errors.root.message}</Alert>}

        <div className="grid grid-cols-2 gap-3">
          <Field label="First name" error={errors.first_name?.message} required>
            <Input {...register('first_name')} />
          </Field>
          <Field label="Last name" error={errors.last_name?.message} required>
            <Input {...register('last_name')} />
          </Field>
        </div>

        <Field label="Middle name" error={errors.middle_name?.message}>
          <Input {...register('middle_name')} />
        </Field>

        <Field label="Email" error={errors.email?.message} required>
          <Input type="email" {...register('email')} />
        </Field>

        <div className="grid grid-cols-2 gap-3">
          <Field label="Employee number" error={errors.employee_number?.message}>
            <Input {...register('employee_number')} />
          </Field>
          <Field label="Contact number" error={errors.contact_number?.message}>
            <Input {...register('contact_number')} />
          </Field>
        </div>
      </form>
    </Drawer>
  )
}

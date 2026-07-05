import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import {
  Alert,
  Button,
  Checkbox,
  Drawer,
  Field,
  Input,
  PasswordInput,
  Select,
} from '@/components/ui'
import { applyServerErrors, getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useCreateUser } from '../hooks/mutations'
import { type CreateUserForm, createUserSchema } from '../schemas'
import type { RoleOption, UserDetail } from '../types'

interface CreateUserDrawerProps {
  open: boolean
  onClose: () => void
  roles: RoleOption[]
  onCreated: (user: UserDetail) => void
}

/** Administrator "Create user" form in a slide-over (SRS FR-USER-001/002). */
export function CreateUserDrawer({ open, onClose, roles, onCreated }: CreateUserDrawerProps) {
  const create = useCreateUser()
  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<CreateUserForm>({
    resolver: zodResolver(createUserSchema),
    defaultValues: { status: 'active', force_password_reset: false, role: '' },
  })

  const submit = handleSubmit(async (values) => {
    try {
      const user = await create.mutateAsync({
        ...values,
        middle_name: values.middle_name || undefined,
        employee_number: values.employee_number || undefined,
        contact_number: values.contact_number || undefined,
      })
      reset()
      onCreated(user)
    } catch (error) {
      if (!applyServerErrors(error, setError)) {
        setError('root', { message: getErrorMessage(error) })
      }
    }
  })

  const close = () => {
    reset()
    onClose()
  }

  return (
    <Drawer
      open={open}
      onClose={close}
      title="Create user"
      description="Administrator-created accounts are active and email-verified by default."
      footer={
        <>
          <Button variant="ghost" size="sm" onClick={close}>
            Cancel
          </Button>
          <Button size="sm" loading={create.isPending} onClick={() => void submit()}>
            Create user
          </Button>
        </>
      }
    >
      <form onSubmit={submit} className="flex flex-col gap-4">
        {errors.root && <Alert tone="error">{errors.root.message}</Alert>}

        <div className="grid grid-cols-2 gap-3">
          <Field label="Role" error={errors.role?.message} required>
            <Select {...register('role')}>
              <option value="">Select…</option>
              {roles.map((role) => (
                <option key={role.slug} value={role.slug ?? ''}>
                  {role.name}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Initial status" error={errors.status?.message}>
            <Select {...register('status')}>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="suspended">Suspended</option>
              <option value="pending">Pending</option>
            </Select>
          </Field>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <Field label="First name" error={errors.first_name?.message} required>
            <Input {...register('first_name')} autoComplete="off" />
          </Field>
          <Field label="Last name" error={errors.last_name?.message} required>
            <Input {...register('last_name')} autoComplete="off" />
          </Field>
        </div>

        <Field label="Middle name" error={errors.middle_name?.message}>
          <Input {...register('middle_name')} autoComplete="off" />
        </Field>

        <Field label="Email" error={errors.email?.message} required>
          <Input type="email" {...register('email')} autoComplete="off" />
        </Field>

        <div className="grid grid-cols-2 gap-3">
          <Field label="Employee number" error={errors.employee_number?.message}>
            <Input {...register('employee_number')} autoComplete="off" />
          </Field>
          <Field label="Contact number" error={errors.contact_number?.message}>
            <Input {...register('contact_number')} autoComplete="off" />
          </Field>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <Field label="Password" error={errors.password?.message} required>
            <PasswordInput {...register('password')} autoComplete="new-password" />
          </Field>
          <Field label="Confirm password" error={errors.password_confirmation?.message} required>
            <PasswordInput {...register('password_confirmation')} autoComplete="new-password" />
          </Field>
        </div>

        <Checkbox
          label="Require a password change at first sign-in"
          {...register('force_password_reset')}
        />
      </form>
    </Drawer>
  )
}

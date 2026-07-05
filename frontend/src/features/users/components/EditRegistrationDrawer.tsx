import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { Alert, Button, Drawer, Field, Input, Select } from '@/components/ui'
import type { RegistrationSummary } from '@/features/auth/types'
import { applyServerErrors, getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useUpdateRegistration } from '../hooks/registrations'
import { type EditRegistrationForm, editRegistrationSchema } from '../schemas'

interface EditRegistrationDrawerProps {
  open: boolean
  onClose: () => void
  registration: RegistrationSummary | null
}

/** Correct a pending registration before approving/rejecting (FR-AUTH-014). */
export function EditRegistrationDrawer({
  open,
  onClose,
  registration,
}: EditRegistrationDrawerProps) {
  const update = useUpdateRegistration()
  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<EditRegistrationForm>({ resolver: zodResolver(editRegistrationSchema) })

  useEffect(() => {
    if (open && registration) {
      reset({
        first_name: registration.first_name,
        middle_name: '',
        last_name: registration.last_name,
        email: registration.email,
        employee_number: registration.employee_number ?? '',
        contact_number: registration.contact_number ?? '',
        role: (registration.role.slug as 'teacher' | 'technician') ?? 'teacher',
      })
    }
  }, [open, registration, reset])

  if (!registration) return null

  const submit = handleSubmit(async (values) => {
    try {
      await update.mutateAsync({
        id: registration.id,
        payload: {
          ...values,
          middle_name: values.middle_name || undefined,
          employee_number: values.employee_number || undefined,
          contact_number: values.contact_number || undefined,
        },
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
      title="Edit registration"
      description="Correct details before approving or rejecting."
      footer={
        <>
          <Button variant="ghost" size="sm" onClick={onClose}>
            Cancel
          </Button>
          <Button size="sm" loading={update.isPending} onClick={() => void submit()}>
            Save
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
        <Field label="Requested role" error={errors.role?.message} required>
          <Select {...register('role')}>
            <option value="teacher">Teacher</option>
            <option value="technician">Technician</option>
          </Select>
        </Field>
      </form>
    </Drawer>
  )
}

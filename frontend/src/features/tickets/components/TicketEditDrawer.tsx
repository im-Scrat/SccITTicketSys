import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useState } from 'react'
import { useForm } from 'react-hook-form'
import { Alert, Button, Drawer, Field, Input, Select, Textarea } from '@/components/ui'
import { applyServerErrors, getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useUpdateTicket } from '../hooks/mutations'
import { useTicketOptions } from '../hooks/queries'
import { type TicketForm, ticketSchema } from '../schemas'
import type { TicketDetail } from '../types'

interface TicketEditDrawerProps {
  open: boolean
  onClose: () => void
  ticket: TicketDetail
}

/**
 * Correct a report's wording (SRS FR-TKT-001).
 *
 * Only the descriptive fields are here. Status, priority, assignment and the
 * duplicate link each have their own audited endpoint, and `UpdateTicketRequest`
 * **prohibits** them on this one rather than accepting and discarding them — so
 * offering any of them in this form would promise a change the API will refuse.
 *
 * A reporter may edit their own ticket only while it is still open; once someone
 * is working it, the description is part of the record they are working from.
 * That rule is the server's, and it reaches this component as `can.update`.
 */
export function TicketEditDrawer({ open, onClose, ticket }: TicketEditDrawerProps) {
  const options = useTicketOptions(open)
  const update = useUpdateTicket(ticket.id)
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<TicketForm>({
    resolver: zodResolver(ticketSchema),
    defaultValues: {
      title: ticket.title,
      description: ticket.description,
      category: ticket.category.slug ?? '',
    },
  })

  useEffect(() => {
    if (!open) return
    setFormError(null)
    reset({
      title: ticket.title,
      description: ticket.description,
      category: ticket.category.slug ?? '',
    })
  }, [open, ticket, reset])

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      await update.mutateAsync({
        title: values.title,
        description: values.description,
        category: values.category,
      })
      onClose()
    } catch (caught) {
      if (!applyServerErrors(caught, setError)) {
        setFormError(getErrorMessage(caught))
      }
    }
  })

  return (
    <Drawer
      open={open}
      onClose={onClose}
      title="Edit this report"
      description="Correct or add to what you wrote. The change is recorded on the ticket's history."
      footer={
        <>
          <Button variant="ghost" onClick={onClose} disabled={isSubmitting}>
            Cancel
          </Button>
          <Button onClick={onSubmit} loading={isSubmitting}>
            Save changes
          </Button>
        </>
      }
    >
      <form onSubmit={onSubmit} className="flex flex-col gap-6">
        {formError && <Alert tone="error">{formError}</Alert>}

        <Field label="What is the problem?" required error={errors.title?.message}>
          <Input {...register('title')} maxLength={255} />
        </Field>

        <Field label="What happened?" required error={errors.description?.message}>
          <Textarea {...register('description')} rows={8} maxLength={10000} />
        </Field>

        <Field label="What kind of problem is it?" required error={errors.category?.message}>
          <Select {...register('category')}>
            <option value="">Choose one…</option>
            {(options.data?.categories ?? []).map((category) => (
              <option key={category.value} value={category.value}>
                {category.label}
              </option>
            ))}
          </Select>
        </Field>
      </form>
    </Drawer>
  )
}

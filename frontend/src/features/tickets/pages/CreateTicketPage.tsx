import { zodResolver } from '@hookform/resolvers/zod'
import { ArrowLeft } from 'lucide-react'
import { useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { useNavigate } from 'react-router-dom'
import { LocationSelect } from '@/components/LocationSelect'
import { PcUnitSelect } from '@/components/PcUnitSelect'
import { Alert, Button, Field, Input, Select, Textarea } from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { applyServerErrors, getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { DuplicateSuggestions } from '../components/DuplicateSuggestions'
import { useCreateTicket } from '../hooks/mutations'
import { useTicketOptions } from '../hooks/queries'
import { type TicketForm, ticketSchema } from '../schemas'

/**
 * Reporting a problem (SRS FR-TKT-001/003, UCS-02).
 *
 * This is the one screen in the product a teacher must be able to use while
 * frustrated, in front of a class, on a machine that is not working. It is
 * therefore a single column of large plain fields with no cleverness: no
 * multi-step wizard, no rich text, no drag-and-drop, nothing that can be got
 * wrong in a way the person has to recover from.
 *
 * Two decisions are worth naming:
 *
 *  - **Similar reports appear as you type the title**, not after submitting.
 *    Telling someone their report was a duplicate once they have finished
 *    writing it wastes the effort they just spent; offering the existing ticket
 *    early makes the upvote the easier path (FR-TKT-011).
 *  - **Priority is not offered to a requester.** The category carries a default
 *    and an administrator adjusts it — the API prohibits the field for them
 *    outright, so rendering it would be a control that lies about what it does.
 *    Staff filing on behalf of someone do see it, because for them the API
 *    accepts it.
 */
export default function CreateTicketPage() {
  useDocumentMeta({ title: 'Report a problem' })
  const navigate = useNavigate()
  const { hasRole } = useAuth()
  const isStaff = hasRole('administrator') || hasRole('technician')

  const options = useTicketOptions()
  const create = useCreateTicket()
  const [formError, setFormError] = useState<string | null>(null)

  const {
    register,
    control,
    handleSubmit,
    watch,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<TicketForm>({
    resolver: zodResolver(ticketSchema),
    defaultValues: {
      title: '',
      description: '',
      category: '',
      pc_unit: '',
      room: '',
      priority: '',
    },
  })

  const title = watch('title')
  const pcUnit = watch('pc_unit')

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null)
    try {
      const created = await create.mutateAsync({
        title: values.title,
        description: values.description,
        category: values.category,
        pc_unit: values.pc_unit || null,
        room: values.room || null,
        ...(isStaff && values.priority ? { priority: values.priority } : {}),
      })
      navigate(`/app/tickets/${created.data.id}`)
    } catch (caught) {
      if (!applyServerErrors(caught, setError)) {
        setFormError(getErrorMessage(caught))
      }
    }
  })

  return (
    <div className="flex flex-col gap-8">
      <div>
        <Button variant="ghost" size="sm" onClick={() => navigate('/app/tickets')}>
          <ArrowLeft size={26} aria-hidden="true" />
          All tickets
        </Button>
      </div>

      <header className="measure">
        <h1 className="text-2xl font-bold text-ink-strong">Report a problem</h1>
        <p className="mt-2 text-base text-muted">
          Describe what is not working. You do not need to know what is wrong with it — say what you
          were doing and what happened, and the IT team will take it from there.
        </p>
      </header>

      <form onSubmit={onSubmit} className="flex flex-col gap-8">
        {formError && <Alert tone="error">{formError}</Alert>}

        <section className="flex max-w-3xl flex-col gap-6 rounded-lg border-2 border-border bg-surface p-8">
          <Field
            label="What is the problem?"
            required
            error={errors.title?.message}
            hint="A short line someone else would recognise — “Projector in Room 204 will not turn on”."
          >
            <Input {...register('title')} maxLength={255} autoFocus />
          </Field>

          <Field
            label="What happened?"
            required
            error={errors.description?.message}
            hint="What you were doing, what you expected, and what happened instead. Any error message helps."
          >
            <Textarea {...register('description')} rows={7} maxLength={10000} />
          </Field>

          <Field
            label="What kind of problem is it?"
            required
            error={errors.category?.message}
            hint="A rough guess is fine — the IT team corrects it if needed."
          >
            <Select {...register('category')}>
              <option value="">Choose one…</option>
              {(options.data?.categories ?? []).map((category) => (
                <option key={category.value} value={category.value}>
                  {category.label}
                </option>
              ))}
            </Select>
          </Field>

          {isStaff && (options.data?.priorities.length ?? 0) > 0 && (
            <Field
              label="Priority"
              error={errors.priority?.message}
              hint="Staff only. Leave blank to use the category's default."
            >
              <Select {...register('priority')}>
                <option value="">Use the category default</option>
                {(options.data?.priorities ?? []).map((priority) => (
                  <option key={priority.value} value={priority.value}>
                    {priority.label}
                  </option>
                ))}
              </Select>
            </Field>
          )}
        </section>

        <section className="flex max-w-3xl flex-col gap-6 rounded-lg border-2 border-border bg-surface p-8">
          <div className="measure">
            <h2 className="text-base font-bold text-ink-strong">Where is it?</h2>
            <p className="mt-2 text-sm text-muted">
              Naming the machine is the most useful thing you can do — it links your report to that
              PC's history, so whoever picks it up already knows what has been done to it before.
              Both fields are optional.
            </p>
          </div>

          <Controller
            control={control}
            name="pc_unit"
            render={({ field }) => (
              <PcUnitSelect
                value={field.value ?? ''}
                onChange={field.onChange}
                error={errors.pc_unit?.message}
              />
            )}
          />

          <Controller
            control={control}
            name="room"
            render={({ field }) => (
              <LocationSelect
                value={field.value ?? ''}
                onChange={field.onChange}
                label="Room"
                allowEmpty
                emptyLabel="No specific room"
                error={errors.room?.message}
              />
            )}
          />
        </section>

        <div className="flex flex-wrap gap-4">
          <Button type="submit" size="lg" loading={isSubmitting}>
            Report this problem
          </Button>
          <Button
            type="button"
            variant="secondary"
            size="lg"
            onClick={() => navigate('/app/tickets')}
          >
            Cancel
          </Button>
        </div>

        <p className="measure text-sm text-muted">You can add photos once the ticket is created.</p>
      </form>

      <DuplicateSuggestions search={title ?? ''} pcUnit={pcUnit || undefined} />
    </div>
  )
}

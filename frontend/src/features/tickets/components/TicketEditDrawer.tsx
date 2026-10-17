import { zodResolver } from '@hookform/resolvers/zod'
import { type BaseSyntheticEvent, useEffect, useMemo, useRef, useState } from 'react'
import { type Path, useForm } from 'react-hook-form'
import { Alert, Button, Drawer, Field, Input, Select, Textarea } from '@/components/ui'
import { applyMappedServerErrors, getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useUpdateTicket } from '../hooks/mutations'
import { useTicketOptions } from '../hooks/queries'
import { CATEGORY_DETAIL_MAX, type EditTicketForm, editTicketSchema } from '../schemas'
import type { TicketDetail } from '../types'
import { CategoryOptions } from './CategoryOptions'

interface TicketEditDrawerProps {
  open: boolean
  onClose: () => void
  ticket: TicketDetail
}

/** Every key this form sends is a field of the same name. */
const SERVER_FIELDS: Partial<Record<string, Path<EditTicketForm>>> = {
  title: 'title',
  description: 'description',
  category: 'category',
  category_detail: 'category_detail',
}

/**
 * Correct a report's wording (SRS FR-TKT-001).
 *
 * Only the descriptive fields are here. Status, priority, assignment and the
 * duplicate link each have their own audited endpoint, and `UpdateTicketRequest`
 * **prohibits** them on this one rather than accepting and discarding them — so
 * offering any of them in this form would promise a change the API will refuse.
 *
 * The "Other" description (SDD DD-77) appears whenever the chosen category asks
 * for one. It is *required* when the category is being changed into such a
 * category, or when the ticket already has a description — an edit may reword
 * it but not erase it. An untouched legacy "Other" ticket, filed before
 * descriptions existed, shows the field without demanding it, and saves as it
 * always did.
 *
 * **Until the categories arrive, the description is not sent at all.** Which
 * categories ask for one is part of the options; without them this form
 * cannot tell, and sending `null` would ask the server to erase a description
 * the ticket has — which it refuses, on a field this form is not showing.
 * Left out, the server keeps what is there.
 *
 * **A refusal is always visible.** A message for a field that is not on screen
 * goes to the alert at the top instead, and focus goes to whichever of the
 * two received it — never left on the page body behind the drawer. Save is
 * not disabled while saving (that would drop focus too); its spinner and a
 * re-entry guard stop a second save.
 *
 * A reporter may edit their own ticket only while it is still open; once someone
 * is working it, the description is part of the record they are working from.
 * That rule is the server's, and it reaches this component as `can.update`.
 */
export function TicketEditDrawer({ open, onClose, ticket }: TicketEditDrawerProps) {
  const options = useTicketOptions(open)
  const update = useUpdateTicket(ticket.id)
  const [formError, setFormError] = useState<string | null>(null)
  const saving = useRef(false)
  const errorRef = useRef<HTMLDivElement>(null)
  const [errorFocus, setErrorFocus] = useState(0)

  const originalCategory = ticket.category.slug ?? ''
  const originalDetail = ticket.category.detail ?? ''

  const categories = options.data?.categories
  const detailSlugs = useMemo(
    () =>
      new Set<string>(
        (categories ?? [])
          .filter((category) => category.requires_detail)
          .map((category) => category.value),
      ),
    [categories],
  )
  const resolver = useMemo(
    () =>
      zodResolver(
        editTicketSchema(detailSlugs, {
          category: originalCategory,
          hasDetail: originalDetail !== '',
        }),
      ),
    [detailSlugs, originalCategory, originalDetail],
  )

  const {
    register,
    handleSubmit,
    reset,
    watch,
    setError,
    clearErrors,
    setFocus,
    formState: { errors, isSubmitting },
  } = useForm<EditTicketForm>({
    resolver,
    defaultValues: {
      title: ticket.title,
      description: ticket.description,
      category: originalCategory,
      category_detail: originalDetail,
    },
  })

  useEffect(() => {
    if (!open) return
    setFormError(null)
    reset({
      title: ticket.title,
      description: ticket.description,
      category: ticket.category.slug ?? '',
      category_detail: ticket.category.detail ?? '',
    })
  }, [open, ticket, reset])

  const category = watch('category')
  const flagged = detailSlugs.has(category)
  const detailRequired = flagged && (category !== originalCategory || originalDetail !== '')

  // The form-level alert takes focus once it is on screen.
  useEffect(() => {
    if (errorFocus > 0) errorRef.current?.focus()
  }, [errorFocus])

  const save = handleSubmit(async (values) => {
    setFormError(null)
    try {
      await update.mutateAsync({
        title: values.title,
        description: values.description,
        category: values.category,
        // Sent once the categories are known, so the server applies its rule
        // to what is on screen: a category that asks for none clears any it
        // had. Before then, left out (see the docblock).
        ...(categories
          ? { category_detail: flagged ? values.category_detail.trim() || null : null }
          : {}),
      })
      onClose()
    } catch (caught) {
      const mapped = applyMappedServerErrors(
        caught,
        setError,
        SERVER_FIELDS,
        (field) => field !== 'category_detail' || flagged,
      )
      const message =
        mapped === null
          ? getErrorMessage(caught)
          : mapped.unplaced.length > 0
            ? mapped.unplaced.join(' ')
            : null

      if (message !== null) {
        setFormError(message)
        setErrorFocus((count) => count + 1)
      } else if (mapped?.placed[0]) {
        setFocus(mapped.placed[0])
      }
    }
  })

  // The re-entry guard: Enter in a field, or a second press, while a save is
  // already on its way does nothing.
  const onSubmit = async (event?: BaseSyntheticEvent) => {
    event?.preventDefault()
    if (saving.current) return
    saving.current = true
    try {
      await save(event)
    } finally {
      saving.current = false
    }
  }

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
      <form onSubmit={onSubmit} noValidate className="flex flex-col gap-6">
        {formError && (
          <div ref={errorRef} tabIndex={-1} className="rounded-md">
            <Alert tone="error">{formError}</Alert>
          </div>
        )}

        <Field label="What is the problem?" required error={errors.title?.message}>
          <Input {...register('title')} maxLength={255} />
        </Field>

        <Field label="What happened?" required error={errors.description?.message}>
          <Textarea {...register('description')} rows={8} maxLength={10000} />
        </Field>

        <Field label="What kind of problem is it?" required error={errors.category?.message}>
          <Select {...register('category', { onChange: () => clearErrors('category_detail') })}>
            <option value="">Choose one…</option>
            <CategoryOptions categories={categories ?? []} />
          </Select>
        </Field>

        {flagged && (
          <Field
            label="Describe the kind of problem"
            required={detailRequired}
            error={errors.category_detail?.message}
            hint="A few words, such as “Interactive whiteboard pen not working”."
          >
            <Input {...register('category_detail')} maxLength={CATEGORY_DETAIL_MAX} />
          </Field>
        )}
      </form>
    </Drawer>
  )
}

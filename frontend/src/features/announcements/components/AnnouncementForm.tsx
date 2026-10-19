import { zodResolver } from '@hookform/resolvers/zod'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { applyServerErrors, getErrorMessage } from '@/features/auth/lib/serverErrors'
import { Alert } from '@/components/ui/Alert'
import { Button } from '@/components/ui/Button'
import { Checkbox } from '@/components/ui/Checkbox'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { type AnnouncementForm as FormValues, announcementSchema } from '../schemas'
import type { Announcement } from '../types'

/** Save only, or save and then publish (which notifies the audience). */
export type AnnouncementIntent = 'save' | 'publish'

interface AnnouncementFormProps {
  announcement?: Announcement
  onSubmit: (values: FormValues, intent: AnnouncementIntent) => Promise<void> | void
  onCancel: () => void
  submitting?: boolean
  /** Which button the pending save came from, so only that one spins. */
  pendingIntent?: AnnouncementIntent | null
}

/** `datetime-local` wants `YYYY-MM-DDTHH:mm`, not an ISO string with a zone. */
function toLocalInput(iso: string | null | undefined): string {
  if (!iso) return ''
  const date = new Date(iso)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

/**
 * Compose or edit an announcement (SRS FR-NOT-010, UC-13).
 *
 * ── Saving is not publishing ───────────────────────────────────────────────
 *
 * There is no "active" switch on this form, and that absence is the feature:
 * an administrator editing a live announcement cannot notify the school a
 * second time by pressing Save (decision D7).
 *
 * A new announcement, or a draft, can be **published straight from the form**
 * (owner request, 2026-10-19): "Publish" saves and then calls the same publish
 * action as the list's button. It is a separate, explicitly named button and
 * never what Enter does. Enter saves a draft, so a stray keypress notifies no
 * one. A published announcement has no Publish here; "Notify again" stays on
 * the list, behind its confirmation.
 *
 * ── Plain text, deliberately ───────────────────────────────────────────────
 *
 * `content` is a `<textarea>` and stays plain text (decision D4). No rich-text
 * control, no Markdown preview, and therefore no HTML sanitisation surface —
 * the value is rendered as escaped text wherever it is read.
 */
export function AnnouncementForm({
  announcement,
  onSubmit,
  onCancel,
  submitting = false,
  pendingIntent = null,
}: AnnouncementFormProps) {
  const [formError, setFormError] = useState<string | null>(null)
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<FormValues>({
    resolver: zodResolver(announcementSchema),
    defaultValues: {
      title: announcement?.title ?? '',
      content: announcement?.content ?? '',
      audience: announcement?.audience ?? 'all',
      starts_at: toLocalInput(announcement?.starts_at),
      ends_at: toLocalInput(announcement?.ends_at),
      is_pinned: announcement?.is_pinned ?? false,
    },
  })

  // A new announcement or an unpublished draft may go out from here.
  const canPublish = !announcement || !announcement.is_active

  const submit = (intent: AnnouncementIntent) =>
    handleSubmit(async (values) => {
      setFormError(null)
      try {
        await onSubmit(values, intent)
      } catch (error) {
        if (!applyServerErrors(error, setError)) {
          setFormError(getErrorMessage(error, 'The announcement could not be saved. Try again.'))
        }
      }
    })

  return (
    <form onSubmit={submit('save')} noValidate className="flex flex-col gap-4">
      {formError && <Alert tone="error">{formError}</Alert>}

      <Field label="Title" error={errors.title?.message} required>
        <Input autoComplete="off" {...register('title')} />
      </Field>

      <Field
        label="Announcement"
        error={errors.content?.message}
        hint="Plain text. Line breaks are kept."
        required
      >
        <Textarea rows={6} {...register('content')} />
      </Field>

      <Field
        label="Audience"
        error={errors.audience?.message}
        hint="Only this audience can read the announcement — it is not just hidden from everyone else."
        required
      >
        <Select {...register('audience')}>
          <option value="all">Everyone</option>
          <option value="teachers">Teachers</option>
          <option value="technicians">Technicians</option>
          <option value="admins">Administrators</option>
        </Select>
      </Field>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Field label="Show from" error={errors.starts_at?.message} hint="Leave empty to start now.">
          <Input type="datetime-local" {...register('starts_at')} />
        </Field>
        <Field
          label="Show until"
          error={errors.ends_at?.message}
          hint="Leave empty to never expire."
        >
          <Input type="datetime-local" {...register('ends_at')} />
        </Field>
      </div>

      <Checkbox label="Pin to the top of the list" {...register('is_pinned')} />

      <p className="text-xs text-muted">
        {canPublish ? (
          <>
            <strong>Publish</strong> notifies the audience now. <strong>Save draft</strong> keeps it
            unpublished and notifies no one.
          </>
        ) : (
          <>
            Saving changes leaves the audience undisturbed — use <strong>Notify again</strong> on
            the list when you want people told.
          </>
        )}
      </p>

      <div className="flex flex-wrap items-center gap-3">
        {canPublish && (
          <Button
            type="button"
            variant="primary"
            size="sm"
            loading={submitting && pendingIntent === 'publish'}
            disabled={submitting && pendingIntent !== 'publish'}
            onClick={submit('publish')}
          >
            {announcement ? 'Save and publish' : 'Publish'}
          </Button>
        )}
        <Button
          type="submit"
          variant={canPublish ? 'secondary' : 'primary'}
          size="sm"
          loading={submitting && pendingIntent !== 'publish'}
          disabled={submitting && pendingIntent === 'publish'}
        >
          {announcement ? 'Save changes' : 'Save draft'}
        </Button>
        <Button type="button" variant="ghost" size="sm" onClick={onCancel} disabled={submitting}>
          Cancel
        </Button>
      </div>
    </form>
  )
}

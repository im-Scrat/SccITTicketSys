import { zodResolver } from '@hookform/resolvers/zod'
import { useForm } from 'react-hook-form'
import { Button } from '@/components/ui/Button'
import { Checkbox } from '@/components/ui/Checkbox'
import { Field } from '@/components/ui/Field'
import { Input } from '@/components/ui/Input'
import { Select } from '@/components/ui/Select'
import { Textarea } from '@/components/ui/Textarea'
import { type AnnouncementForm as FormValues, announcementSchema } from '../schemas'
import type { Announcement } from '../types'

interface AnnouncementFormProps {
  announcement?: Announcement
  onSubmit: (values: FormValues) => Promise<void> | void
  onCancel: () => void
  submitting?: boolean
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
 * There is no "active" switch on this form, and that absence is the feature.
 * Publication is a separate action on the list, so an administrator editing a
 * live announcement cannot notify the school a second time by pressing Save
 * (decision D7). The copy below says so, because a form that silently declines
 * to do something has to explain itself.
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
}: AnnouncementFormProps) {
  const {
    register,
    handleSubmit,
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

  return (
    <form onSubmit={handleSubmit(onSubmit)} noValidate className="flex flex-col gap-4">
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
        <Field label="Show until" error={errors.ends_at?.message} hint="Leave empty to never expire.">
          <Input type="datetime-local" {...register('ends_at')} />
        </Field>
      </div>

      <Checkbox label="Pin to the top of the list" {...register('is_pinned')} />

      <p className="text-xs text-muted">
        Saving does not notify anyone. A new announcement is created as a draft, and editing a
        published one leaves its audience undisturbed — use <strong>Publish</strong> or{' '}
        <strong>Notify again</strong> when you want people told.
      </p>

      <div className="flex items-center gap-3">
        <Button type="submit" variant="primary" size="sm" loading={submitting}>
          {announcement ? 'Save changes' : 'Create draft'}
        </Button>
        <Button type="button" variant="ghost" size="sm" onClick={onCancel} disabled={submitting}>
          Cancel
        </Button>
      </div>
    </form>
  )
}

import { z } from 'zod'

/**
 * The announcement form contract (SRS FR-NOT-010).
 *
 * Mirrors `StoreAnnouncementRequest` and, through it, the database's
 * `announcements_date_order_check`. Three layers say the same thing about the
 * window because each catches it at a different moment: this one before a
 * request is sent, the FormRequest with a 422 naming the field, and the CHECK
 * constraint for every caller that is not this form.
 *
 * There is no `is_active` field. Publication is an operation, not something a
 * form submits (WP-2.7c decision D7).
 */
export const announcementSchema = z
  .object({
    title: z.string().trim().min(1, 'Give the announcement a title.').max(200),
    content: z
      .string()
      .trim()
      .min(1, 'An announcement needs something to say.')
      .max(5000, 'Announcements are capped at 5000 characters.'),
    audience: z.enum(['all', 'teachers', 'technicians', 'admins']),
    // Empty strings come from untouched date inputs; they mean "no bound".
    starts_at: z.string().optional(),
    ends_at: z.string().optional(),
    is_pinned: z.boolean().optional(),
  })
  .refine(
    (values) =>
      !values.starts_at ||
      !values.ends_at ||
      new Date(values.ends_at).getTime() > new Date(values.starts_at).getTime(),
    {
      message: 'The end of the window must come after its start.',
      path: ['ends_at'],
    },
  )

export type AnnouncementForm = z.infer<typeof announcementSchema>

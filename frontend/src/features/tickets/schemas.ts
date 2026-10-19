import { z } from 'zod'

/**
 * Client-side mirrors of the backend FormRequest rules (SRS FR-TKT-001/003/007)
 * so the form answers before a round trip. The server remains authoritative — it
 * also owns the rules this cannot see, such as whether a chosen room is still in
 * use or a chosen item is still in that room.
 *
 * The minimums are copied from `StoreTicketRequest` deliberately, including its
 * wording: a reporter told "at least 10 characters — what you were doing, and
 * what happened" by the form and then told something different by the server
 * would rightly conclude the form was guessing.
 */

const title = z
  .string()
  .min(5, 'Give the problem a title of at least 5 characters, so it can be recognised in the feed.')
  .max(255, 'Use 255 characters or fewer.')

const description = z
  .string()
  .min(
    10,
    'Describe what is wrong in at least 10 characters — what you were doing, and what happened.',
  )
  .max(10000, 'Use 10,000 characters or fewer.')

const category = z.string().min(1, 'Choose the kind of problem this is.')

/** `ticket_categories.requires_detail` → `tickets.category_detail` (SDD DD-77). */
export const CATEGORY_DETAIL_MIN = 3
export const CATEGORY_DETAIL_MAX = 120

const categoryDetail = z
  .string()
  .max(CATEGORY_DETAIL_MAX, `Use ${CATEGORY_DETAIL_MAX} characters or fewer.`)

const DETAIL_REQUIRED = 'Say in a few words what kind of problem it is — at least 3 characters.'

const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'

/**
 * The report form's one "which item" answer: empty, `pc:<uuid>` or
 * `asset:<uuid>`. One field rather than two is what makes a PC *and* an asset
 * impossible to choose together — the server refuses the pair, and this form
 * cannot produce it.
 */
export const ITEM_PATTERN = new RegExp(`^$|^(pc|asset):${UUID}$`)

/**
 * The report form (SRS FR-TKT-001; SDD DD-75..DD-80).
 *
 * `detailSlugs` are the categories the options endpoint flags as needing a
 * description. They come from the server rather than a list kept here, so an
 * administrator flagging a new category needs no deployment.
 *
 * `building` is the cascade's first step only — a ticket stores a room, never a
 * building on its own (the "Where is it?" copy says so).
 */
export function reportSchema(detailSlugs: ReadonlySet<string>) {
  return z
    .object({
      title,
      description,
      category,
      category_detail: categoryDetail,
      /** '' — "Not sure, let the IT team decide": the category's default applies. */
      priority: z.string(),
      building: z.string(),
      room: z.string(),
      item: z.string().regex(ITEM_PATTERN, 'Choose an item from the list.'),
    })
    .refine(
      (values) =>
        !detailSlugs.has(values.category) ||
        (values.category_detail ?? '').trim().length >= CATEGORY_DETAIL_MIN,
      // `when` lets this run alongside the other fields' errors, so a reporter
      // who skipped several fields sees every message at once, not in rounds.
      { path: ['category_detail'], message: DETAIL_REQUIRED, when: () => true },
    )
}
export type ReportForm = z.infer<ReturnType<typeof reportSchema>>

/**
 * Correcting a report (SRS FR-TKT-001), with the "Other" description.
 *
 * The description is required when the category is being changed *into* a
 * flagged one, or when the ticket already has a description — an edit may
 * reword it, never erase it. An untouched legacy "Other" ticket filed before
 * descriptions existed still saves without one, as the server allows.
 */
export function editTicketSchema(
  detailSlugs: ReadonlySet<string>,
  original: { category: string; hasDetail: boolean },
) {
  return z.object({ title, description, category, category_detail: categoryDetail }).refine(
    (values) => {
      if (!detailSlugs.has(values.category)) return true
      const length = (values.category_detail ?? '').trim().length
      const required = values.category !== original.category || original.hasDetail
      return required
        ? length >= CATEGORY_DETAIL_MIN
        : length === 0 || length >= CATEGORY_DETAIL_MIN
    },
    { path: ['category_detail'], message: DETAIL_REQUIRED, when: () => true },
  )
}
export type EditTicketForm = z.infer<ReturnType<typeof editTicketSchema>>

export const commentSchema = z.object({
  body: z
    .string()
    .min(1, 'Write something before posting.')
    .max(5000, 'Use 5,000 characters or fewer.'),
  is_internal: z.boolean().optional(),
})
export type CommentForm = z.infer<typeof commentSchema>

/**
 * A decline sends the ticket back to the queue, so the reason is the only thing
 * telling an administrator how to place it better — required, and long enough to
 * mean something.
 */
export const declineSchema = z.object({
  reason: z
    .string()
    .min(5, 'Say why, in at least 5 characters — this is what the administrator reassigns from.')
    .max(2000, 'Use 2,000 characters or fewer.'),
})
export type DeclineForm = z.infer<typeof declineSchema>

export const remarksSchema = z.object({
  remarks: z.string().max(2000, 'Use 2,000 characters or fewer.').optional().or(z.literal('')),
})
export type RemarksForm = z.infer<typeof remarksSchema>

export const assignSchema = z.object({
  technician: z.string().min(1, 'Choose who should take this.'),
  remarks: z.string().max(2000, 'Use 2,000 characters or fewer.').optional().or(z.literal('')),
})
export type AssignForm = z.infer<typeof assignSchema>

export const prioritySchema = z.object({
  priority: z.string().min(1, 'Choose a priority.'),
  reason: z.string().max(2000, 'Use 2,000 characters or fewer.').optional().or(z.literal('')),
})
export type PriorityForm = z.infer<typeof prioritySchema>

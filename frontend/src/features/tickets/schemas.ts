import { z } from 'zod'

/**
 * Client-side mirrors of the backend FormRequest rules (SRS FR-TKT-001/003/007)
 * so the form answers before a round trip. The server remains authoritative — it
 * also owns the rules this cannot see, such as whether the caller is allowed to
 * set a priority at all.
 *
 * The minimums are copied from `StoreTicketRequest` deliberately, including its
 * wording: a reporter told "at least 10 characters — what you were doing, and
 * what happened" by the form and then told something different by the server
 * would rightly conclude the form was guessing.
 */

export const ticketSchema = z.object({
  title: z
    .string()
    .min(
      5,
      'Give the problem a title of at least 5 characters, so it can be recognised in the feed.',
    )
    .max(255, 'Use 255 characters or fewer.'),
  description: z
    .string()
    .min(
      10,
      'Describe what is wrong in at least 10 characters — what you were doing, and what happened.',
    )
    .max(10000, 'Use 10,000 characters or fewer.'),
  category: z.string().min(1, 'Choose the kind of problem this is.'),
  /** Both optional: a fault that is not about one machine is still a fault. */
  pc_unit: z.string().optional().or(z.literal('')),
  room: z.string().optional().or(z.literal('')),
  tags: z.array(z.string()).max(10, 'Choose up to 10 tags.').optional(),
  /**
   * Staff-only. Present in the schema because the same form serves a technician
   * filing on behalf of a teacher; the field is not rendered for a requester,
   * and the API prohibits it for them regardless.
   */
  priority: z.string().optional().or(z.literal('')),
})
export type TicketForm = z.infer<typeof ticketSchema>

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

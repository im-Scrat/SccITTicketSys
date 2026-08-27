import { Lock, Pencil, Trash2 } from 'lucide-react'
import { useState } from 'react'
import {
  Alert,
  Badge,
  Button,
  Checkbox,
  ConfirmDialog,
  EmptyState,
  Pagination,
  Skeleton,
  Textarea,
} from '@/components/ui'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { formatRelative } from '@/lib/datetime'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { useDeleteComment, usePostComment, useUpdateComment } from '../hooks/mutations'
import { useTicketComments } from '../hooks/queries'
import type { TicketComment } from '../types'

interface TicketCommentsProps {
  ticketId: string
  canComment: boolean
  /** Staff only — an internal note the reporter never receives. */
  canCommentInternal: boolean
  /** A closed ticket keeps its conversation readable but not writable. */
  readOnly?: boolean
}

/**
 * The ticket conversation (SRS FR-TKT-007).
 *
 * Two things here are worth reading carefully.
 *
 * **Internal notes never arrive at a requester.** The comment query excludes
 * them server-side rather than this component hiding them, so what a teacher's
 * browser holds does not contain a staff note at all. `is_internal` is still
 * carried on the ones staff *do* receive, because a technician needs to know
 * which of their own words the reporter can read — a note that looks like a
 * public reply is how a private remark gets said out loud.
 *
 * **A removed comment renders as a tombstone**, not a gap. Deleting the row
 * outright would silently reshape a conversation, leaving replies answering
 * nothing; the placeholder keeps the thread honest about what happened.
 */
export function TicketComments({
  ticketId,
  canComment,
  canCommentInternal,
  readOnly = false,
}: TicketCommentsProps) {
  const { hasRole } = useAuth()
  const isAdministrator = hasRole('administrator')

  const [page, setPage] = useState(1)
  const comments = useTicketComments(ticketId, page)

  const post = usePostComment(ticketId)
  const update = useUpdateComment()
  const remove = useDeleteComment()

  const [body, setBody] = useState('')
  const [internal, setInternal] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [editing, setEditing] = useState<TicketComment | null>(null)
  const [editBody, setEditBody] = useState('')
  const [deleting, setDeleting] = useState<TicketComment | null>(null)

  const submit = async () => {
    if (body.trim().length === 0) return
    setError(null)
    try {
      await post.mutateAsync({ body: body.trim(), isInternal: internal })
      setBody('')
      setInternal(false)
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  const saveEdit = async () => {
    if (!editing || editBody.trim().length === 0) return
    setError(null)
    try {
      await update.mutateAsync({ commentId: editing.id, body: editBody.trim() })
      setEditing(null)
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  const confirmDelete = async () => {
    if (!deleting) return
    setError(null)
    try {
      await remove.mutateAsync(deleting.id)
    } catch (caught) {
      setError(getErrorMessage(caught))
    } finally {
      setDeleting(null)
    }
  }

  const rows = comments.data?.data ?? []

  return (
    <div className="flex flex-col gap-8">
      {error && <Alert tone="error">{error}</Alert>}

      {comments.isLoading ? (
        <div className="flex flex-col gap-4">
          {Array.from({ length: 3 }).map((_, index) => (
            <Skeleton key={index} className="h-28 rounded-lg" />
          ))}
        </div>
      ) : rows.length === 0 ? (
        <EmptyState
          title="No comments yet"
          description="Questions, updates and anything that helps whoever picks this up belong here."
        />
      ) : (
        <ul className="flex flex-col gap-4">
          {rows.map((comment) => (
            <li
              key={comment.id}
              className={
                comment.is_internal
                  ? 'rounded-lg border-2 border-warning-strong bg-warning-subtle p-6'
                  : 'rounded-lg border-2 border-border bg-surface p-6'
              }
            >
              <div className="flex flex-wrap items-center gap-3">
                <span className="text-sm font-bold text-ink-strong">
                  {comment.author?.name ?? 'Removed user'}
                </span>
                {comment.author?.role && (
                  <Badge tone="outline" className="capitalize">
                    {comment.author.role}
                  </Badge>
                )}
                {comment.is_internal && (
                  <Badge tone="warning" icon={<Lock size={16} aria-hidden="true" />}>
                    Internal note — not shown to the reporter
                  </Badge>
                )}
                <span className="text-xs text-muted">{formatRelative(comment.created_at)}</span>
                {comment.is_edited && <span className="text-xs text-muted">· edited</span>}
              </div>

              {editing?.id === comment.id ? (
                <div className="mt-4 flex flex-col gap-4">
                  <Textarea
                    value={editBody}
                    rows={4}
                    onChange={(event) => setEditBody(event.target.value)}
                    aria-label="Edit your comment"
                  />
                  <div className="flex flex-wrap gap-3">
                    <Button onClick={saveEdit} loading={update.isPending}>
                      Save changes
                    </Button>
                    <Button variant="secondary" onClick={() => setEditing(null)}>
                      Cancel
                    </Button>
                  </div>
                </div>
              ) : comment.removed ? (
                <p className="mt-4 text-base italic text-muted">
                  This comment was removed
                  {comment.moderated_by ? ` by ${comment.moderated_by}` : ''}.
                </p>
              ) : (
                <p className="measure mt-4 whitespace-pre-wrap text-base text-ink">
                  {comment.body}
                </p>
              )}

              {!comment.removed && !readOnly && editing?.id !== comment.id && (
                <div className="mt-4 flex flex-wrap gap-3">
                  {comment.is_mine && (
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => {
                        setEditing(comment)
                        setEditBody(comment.body ?? '')
                      }}
                    >
                      <Pencil size={26} aria-hidden="true" />
                      Edit
                    </Button>
                  )}
                  {(comment.is_mine || isAdministrator) && (
                    <Button variant="ghost" size="sm" onClick={() => setDeleting(comment)}>
                      <Trash2 size={26} aria-hidden="true" />
                      Remove
                    </Button>
                  )}
                </div>
              )}
            </li>
          ))}
        </ul>
      )}

      {comments.data && comments.data.meta.last_page > 1 && (
        <Pagination
          page={comments.data.meta.current_page}
          lastPage={comments.data.meta.last_page}
          total={comments.data.meta.total}
          from={comments.data.meta.from}
          to={comments.data.meta.to}
          onPage={setPage}
        />
      )}

      {canComment && !readOnly && (
        <div className="flex flex-col gap-4 rounded-lg border-2 border-border bg-surface p-6">
          <label htmlFor="new-comment" className="text-sm font-semibold text-ink">
            Add a comment
          </label>
          <Textarea
            id="new-comment"
            value={body}
            rows={4}
            maxLength={5000}
            placeholder="Anything that helps — what you have already tried, when it happens, who else it affects."
            onChange={(event) => setBody(event.target.value)}
          />

          {canCommentInternal && (
            <Checkbox
              label="Internal note — visible to the IT team only, never to the reporter"
              checked={internal}
              onChange={(event) => setInternal(event.target.checked)}
            />
          )}

          <div>
            <Button onClick={submit} loading={post.isPending} disabled={body.trim().length === 0}>
              {internal ? 'Add internal note' : 'Post comment'}
            </Button>
          </div>
        </div>
      )}

      <ConfirmDialog
        open={deleting !== null}
        onClose={() => setDeleting(null)}
        onConfirm={confirmDelete}
        title="Remove this comment?"
        confirmLabel="Remove"
        tone="danger"
        loading={remove.isPending}
        description="The thread keeps a placeholder in its place, so the conversation still reads correctly. The removal is recorded in the audit log."
      />
    </div>
  )
}

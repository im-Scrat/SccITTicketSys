import { Lock, Pencil, Reply, Trash2 } from 'lucide-react'
import { useEffect, useId, useRef, useState } from 'react'
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
import { cn } from '@/lib/cn'
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
  /** Readable but not writable — e.g. a technician's past assignment. */
  readOnly?: boolean
}

/** Who a reply is being written to: the thread it joins, and the comment answered. */
interface ReplyTarget {
  root: TicketComment
  /** The author of the comment being answered — the root's, or a reply's. */
  name: string
  /** Pre-filled when answering a reply, so the thread shows who is answered. */
  mention: string
}

/**
 * The ticket conversation (SRS FR-TKT-007).
 *
 * Three things here are worth reading carefully.
 *
 * **Internal notes never arrive at a requester.** The comment query excludes
 * them server-side — replies included — rather than this component hiding them,
 * so what a teacher's browser holds does not contain a staff note at all.
 * `is_internal` is still carried on the ones staff *do* receive, because a
 * technician needs to know which of their own words the reporter can read — a
 * note that looks like a public reply is how a private remark gets said out loud.
 *
 * **Threads are one level deep (SDD DD-74).** Every reply hangs off a top-level
 * comment; answering a reply joins the same thread with an "@Name" mention, the
 * way YouTube does it. Deeper trees get unreadable in a dialog and on a phone,
 * and the server pages by thread so a conversation is never split across pages.
 * A reply takes its thread's visibility: replies to an internal note stay
 * internal (the server refuses a public one).
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
  const composerId = useId()
  const replyFieldId = useId()

  const [page, setPage] = useState(1)
  const comments = useTicketComments(ticketId, page)

  const post = usePostComment(ticketId)
  const reply = usePostComment(ticketId)
  const update = useUpdateComment()
  const remove = useDeleteComment()

  const [body, setBody] = useState('')
  const [internal, setInternal] = useState(false)
  const [postError, setPostError] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [editing, setEditing] = useState<TicketComment | null>(null)
  const [editBody, setEditBody] = useState('')
  const [deleting, setDeleting] = useState<TicketComment | null>(null)

  const [replyTo, setReplyTo] = useState<ReplyTarget | null>(null)
  const [replyBody, setReplyBody] = useState('')
  const [replyError, setReplyError] = useState<string | null>(null)
  const replyField = useRef<HTMLTextAreaElement>(null)
  const replyOpener = useRef<HTMLElement | null>(null)

  const writable = canComment && !readOnly

  // Opening a reply puts the caret at the end of the field, after any mention.
  useEffect(() => {
    const field = replyField.current
    if (!replyTo || !field) return
    field.focus()
    field.setSelectionRange(field.value.length, field.value.length)
  }, [replyTo])

  const submit = async () => {
    if (body.trim().length === 0) return
    setPostError(null)
    try {
      await post.mutateAsync({ body: body.trim(), isInternal: internal })
      setBody('')
      setInternal(false)
    } catch (caught) {
      setPostError(getErrorMessage(caught))
    }
  }

  const startReply = (root: TicketComment, target: TicketComment) => {
    replyOpener.current =
      document.activeElement instanceof HTMLElement ? document.activeElement : null
    const mention =
      target.id !== root.id && !target.is_mine && target.author?.name
        ? `@${target.author.name} `
        : ''
    setReplyError(null)
    setReplyBody(mention)
    setReplyTo({ root, name: target.author?.name ?? 'a removed user', mention })
  }

  const closeReply = () => {
    setReplyTo(null)
    setReplyBody('')
    setReplyError(null)
    // Back to the Reply button that opened it, once the field has unmounted.
    const opener = replyOpener.current
    requestAnimationFrame(() => {
      if (opener?.isConnected) opener.focus()
    })
  }

  // A reply that is only the pre-filled mention has nothing to say yet.
  const replyIsEmpty =
    replyBody.trim().length === 0 || replyBody.trim() === (replyTo?.mention.trim() ?? '')

  const submitReply = async () => {
    if (!replyTo || replyIsEmpty) return
    setReplyError(null)
    try {
      await reply.mutateAsync({
        body: replyBody.trim(),
        isInternal: replyTo.root.is_internal,
        parent: replyTo.root.id,
      })
      closeReply()
    } catch (caught) {
      setReplyError(getErrorMessage(caught))
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

  /** The pieces of a comment that do not depend on where it sits in a thread. */
  const itemProps = (comment: TicketComment, onReply?: () => void) => ({
    comment,
    readOnly,
    isEditing: editing?.id === comment.id,
    editBody,
    onEditBodyChange: setEditBody,
    onSaveEdit: saveEdit,
    onCancelEdit: () => setEditing(null),
    saving: update.isPending,
    // The server decides who may touch a comment (author inside the edit
    // window; an administrator may remove but never edit someone else's) and
    // says so per comment — the buttons follow that, not a guess from roles.
    onEdit: comment.can_edit
      ? () => {
          setEditing(comment)
          setEditBody(comment.body ?? '')
        }
      : undefined,
    onRemove: comment.can_remove ? () => setDeleting(comment) : undefined,
    onReply,
  })

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
          {rows.map((root) => {
            const replies = root.replies ?? []
            // A removed comment cannot be answered — the server refuses it — and
            // neither can its replies, which would join the same thread.
            const replyable = writable && !root.removed
            const replyingHere = replyTo?.root.id === root.id
            const authorName = root.author?.name ?? 'a removed user'

            return (
              <li
                key={root.id}
                className={cn(
                  'rounded-lg border-2 p-6',
                  root.is_internal
                    ? 'border-warning-strong bg-warning-subtle'
                    : 'border-border bg-surface',
                )}
              >
                <CommentItem
                  {...itemProps(root, replyable ? () => startReply(root, root) : undefined)}
                />

                {(replies.length > 0 || replyingHere) && (
                  <div className="mt-6 border-t border-border pl-4 sm:pl-10">
                    {replies.length > 0 && (
                      <ul
                        aria-label={`Replies to ${authorName}`}
                        className="flex flex-col divide-y divide-border"
                      >
                        {replies.map((child) => (
                          <li key={child.id} className={cn('py-5', !replyingHere && 'last:pb-0')}>
                            <CommentItem
                              {...itemProps(
                                child,
                                replyable && !child.removed
                                  ? () => startReply(root, child)
                                  : undefined,
                              )}
                            />
                          </li>
                        ))}
                      </ul>
                    )}

                    {replyingHere && (
                      <div
                        className={cn(
                          'flex flex-col gap-4 pt-5',
                          replies.length > 0 && 'border-t border-border',
                        )}
                      >
                        {/* Not "Reply to …": that is the Reply button's name, and
                            a field and a button must not share one. */}
                        <label htmlFor={replyFieldId} className="text-sm font-semibold text-ink">
                          Your reply to {replyTo?.name}
                        </label>
                        <Textarea
                          ref={replyField}
                          id={replyFieldId}
                          value={replyBody}
                          rows={3}
                          maxLength={5000}
                          onChange={(event) => setReplyBody(event.target.value)}
                          onKeyDown={(event) => {
                            // Escape abandons the reply, not the dialog around it.
                            if (event.key === 'Escape') {
                              event.stopPropagation()
                              closeReply()
                            }
                          }}
                        />
                        {replyError && <Alert tone="error">{replyError}</Alert>}
                        <div className="flex flex-wrap gap-3">
                          <Button
                            onClick={submitReply}
                            loading={reply.isPending}
                            disabled={replyIsEmpty}
                          >
                            Post reply
                          </Button>
                          <Button variant="secondary" onClick={closeReply}>
                            Cancel
                          </Button>
                        </div>
                      </div>
                    )}
                  </div>
                )}
              </li>
            )
          })}
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

      {writable && (
        <div className="flex flex-col gap-4 rounded-lg border-2 border-border bg-surface p-6">
          <label htmlFor={composerId} className="text-sm font-semibold text-ink">
            Add a comment
          </label>
          <Textarea
            id={composerId}
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

          {postError && <Alert tone="error">{postError}</Alert>}

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

interface CommentItemProps {
  comment: TicketComment
  readOnly: boolean
  isEditing: boolean
  editBody: string
  onEditBodyChange: (value: string) => void
  onSaveEdit: () => void
  onCancelEdit: () => void
  saving: boolean
  /** Each action is offered only when its handler is given. */
  onEdit?: () => void
  onRemove?: () => void
  onReply?: () => void
}

/** One comment — a thread's top-level comment and its replies look alike. */
function CommentItem({
  comment,
  readOnly,
  isEditing,
  editBody,
  onEditBodyChange,
  onSaveEdit,
  onCancelEdit,
  saving,
  onEdit,
  onRemove,
  onReply,
}: CommentItemProps) {
  const name = comment.author?.name ?? 'Removed user'
  const showActions =
    !comment.removed && !isEditing && (onReply || (!readOnly && (onEdit || onRemove)))

  return (
    <div>
      <div className="flex flex-wrap items-center gap-3">
        <span className="text-sm font-bold text-ink-strong">{name}</span>
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

      {isEditing ? (
        <div className="mt-4 flex flex-col gap-4">
          <Textarea
            value={editBody}
            rows={4}
            onChange={(event) => onEditBodyChange(event.target.value)}
            aria-label="Edit your comment"
          />
          <div className="flex flex-wrap gap-3">
            <Button onClick={onSaveEdit} loading={saving}>
              Save changes
            </Button>
            <Button variant="secondary" onClick={onCancelEdit}>
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
        <p className="measure mt-4 whitespace-pre-wrap wrap-break-word text-base text-ink">
          {comment.body}
        </p>
      )}

      {showActions && (
        <div className="mt-4 flex flex-wrap gap-3">
          {onReply && (
            <Button
              variant="ghost"
              size="sm"
              onClick={onReply}
              aria-label={`Reply to ${name}`}
              leftIcon={<Reply size={20} aria-hidden="true" />}
            >
              Reply
            </Button>
          )}
          {!readOnly && onEdit && (
            <Button
              variant="ghost"
              size="sm"
              onClick={onEdit}
              leftIcon={<Pencil size={20} aria-hidden="true" />}
            >
              Edit
            </Button>
          )}
          {!readOnly && onRemove && (
            <Button
              variant="ghost"
              size="sm"
              onClick={onRemove}
              leftIcon={<Trash2 size={20} aria-hidden="true" />}
            >
              Remove
            </Button>
          )}
        </div>
      )}
    </div>
  )
}

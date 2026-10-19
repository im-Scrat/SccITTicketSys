import { AlertCircle, Check, Loader2, PencilLine, Users } from 'lucide-react'
import { cn } from '@/lib/cn'
import type { PresenceMember } from '@/hooks/usePresenceChannel'

export type SaveState =
  | { kind: 'saved'; at: string | null; by: string | null }
  | { kind: 'unsaved'; count: number }
  | { kind: 'saving' }
  | { kind: 'failed'; message: string }

/**
 * Where the draft stands, in words (Phase 2.11). Always text plus an icon;
 * `role="status"` so a change is announced without moving focus.
 */
export function SaveStateBadge({ state, onRetry }: { state: SaveState; onRetry?: () => void }) {
  const base = 'inline-flex items-center gap-2 rounded-full border px-3 py-1 text-sm font-medium'

  if (state.kind === 'saving') {
    return (
      <span role="status" className={cn(base, 'border-border text-muted')}>
        <Loader2 className="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />
        Saving…
      </span>
    )
  }
  if (state.kind === 'unsaved') {
    return (
      <span
        role="status"
        className={cn(base, 'border-warning bg-warning-subtle text-warning-strong')}
      >
        <PencilLine className="size-4" aria-hidden="true" />
        Unsaved changes ({state.count})
      </span>
    )
  }
  if (state.kind === 'failed') {
    return (
      <span role="status" className={cn(base, 'border-danger bg-danger-subtle text-danger-strong')}>
        <AlertCircle className="size-4" aria-hidden="true" />
        Couldn’t save
        {onRetry && (
          <button
            type="button"
            onClick={onRetry}
            className="underline underline-offset-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
          >
            Retry
          </button>
        )}
      </span>
    )
  }
  return (
    <span role="status" className={cn(base, 'border-border text-muted')}>
      <Check className="size-4 text-success-strong" aria-hidden="true" />
      {state.at ? `Saved ${relative(state.at)}${state.by ? ` by ${state.by}` : ''}` : 'Saved'}
    </span>
  )
}

function relative(iso: string): string {
  const seconds = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000)
  if (seconds < 60) return 'just now'
  const minutes = Math.round(seconds / 60)
  if (minutes < 60) return `${minutes} min ago`
  const hours = Math.round(minutes / 60)
  if (hours < 24) return `${hours} h ago`
  return new Date(iso).toLocaleDateString()
}

/**
 * Who else has this room open (presence) and whether they are editing — so two
 * administrators see each other before their saves collide.
 */
export function PresenceIndicator({
  members,
  selfId,
  editing,
}: {
  members: PresenceMember[]
  selfId: string | undefined
  /** Member ids currently in edit mode (from their whispers). */
  editing: ReadonlySet<string>
}) {
  const others = members.filter((m) => m.id !== selfId)
  if (others.length === 0) return null

  const names = others.map((m) => (editing.has(m.id) ? `${m.name} (editing)` : m.name))
  const anyEditing = others.some((m) => editing.has(m.id))

  return (
    <span
      role="status"
      className={cn(
        'inline-flex items-center gap-2 rounded-full border px-3 py-1 text-sm font-medium',
        anyEditing
          ? 'border-warning bg-warning-subtle text-warning-strong'
          : 'border-border text-muted',
      )}
    >
      <Users className="size-4" aria-hidden="true" />
      Also here: {names.join(', ')}
    </span>
  )
}

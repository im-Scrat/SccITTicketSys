import { ArrowBigUp } from 'lucide-react'
import { useEffect, useState } from 'react'
import { cn } from '@/lib/cn'
import { useToggleVote } from '../hooks/mutations'

interface VoteButtonProps {
  ticketId: string
  upvoteCount: number
  hasVoted: boolean
  /** False on a closed ticket, or where the caller may not vote. */
  disabled?: boolean
  size?: 'sm' | 'md'
}

/**
 * "This is affecting me too" (SRS FR-TKT-009).
 *
 * The upvote is what makes the community feed worth reading: a second teacher
 * who finds the printer already reported adds weight to that ticket instead of
 * filing a duplicate, and the administrator triaging the queue can see which
 * fault is blocking a whole department.
 *
 * The count moves the moment it is clicked and is then reconciled with the
 * server's authoritative number, which comes from the database counter-cache
 * trigger. A vote is the lightest interaction on the page; waiting a round trip
 * to acknowledge it makes it feel broken. If the request fails, the count
 * returns to where it was and the button reverts — no error banner, because a
 * failed vote is not something the reporter needs to act on.
 */
export function VoteButton({
  ticketId,
  upvoteCount,
  hasVoted,
  disabled = false,
  size = 'md',
}: VoteButtonProps) {
  const toggle = useToggleVote(ticketId)
  const [voted, setVoted] = useState(hasVoted)
  const [count, setCount] = useState(upvoteCount)

  // Re-sync when the row is re-fetched (another tab, a list refresh).
  useEffect(() => {
    setVoted(hasVoted)
    setCount(upvoteCount)
  }, [hasVoted, upvoteCount])

  const onClick = async () => {
    const previous = { voted, count }
    setVoted(!voted)
    setCount(count + (voted ? -1 : 1))

    try {
      const result = await toggle.mutateAsync()
      setVoted(result.voted)
      setCount(result.upvote_count)
    } catch {
      setVoted(previous.voted)
      setCount(previous.count)
    }
  }

  const label = voted ? 'Remove your upvote' : 'Upvote — this affects me too'

  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      aria-pressed={voted}
      aria-label={label}
      title={label}
      className={cn(
        'inline-flex shrink-0 flex-col items-center justify-center gap-0.5 rounded-md border-2 font-semibold',
        'transition-colors duration-150 [transition-timing-function:var(--ease-standard)]',
        size === 'sm' ? 'h-15 w-15 text-xs' : 'h-20 w-20 text-sm',
        voted
          ? 'border-primary bg-primary-subtle text-primary-strong'
          : 'border-control-border bg-surface text-ink hover:bg-surface-sunken',
        disabled &&
          'cursor-not-allowed border-border bg-surface-sunken text-faint hover:bg-surface-sunken',
      )}
    >
      <ArrowBigUp size={size === 'sm' ? 24 : 32} aria-hidden="true" />
      <span className="tnum">{count}</span>
    </button>
  )
}

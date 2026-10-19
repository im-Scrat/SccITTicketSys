import type { ReactNode } from 'react'

/**
 * The wizard's sticky bottom bar: the one primary action of the step, and —
 * when it is disabled — the reason, in words, beside it. It sits above the
 * page content (the page reserves bottom padding for it) and below overlays.
 */
export function ImportActionBar({
  reason,
  children,
}: {
  reason: string | null
  children: ReactNode
}) {
  return (
    <div className="sticky bottom-0 z-40 -mx-4 border-t-2 border-border bg-bg px-4 py-4 sm:-mx-6 sm:px-6">
      <div className="mx-auto flex max-w-[96rem] flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end sm:gap-x-6">
        {reason && (
          <p className="min-w-0 text-sm text-warning-strong sm:flex-1" role="status">
            {reason}
          </p>
        )}
        <div className="flex flex-wrap gap-3 [&>*]:flex-1 sm:[&>*]:flex-none">{children}</div>
      </div>
    </div>
  )
}

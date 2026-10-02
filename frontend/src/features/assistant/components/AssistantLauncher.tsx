import { Sparkles } from 'lucide-react'
import { useRef, useState } from 'react'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { cn } from '@/lib/cn'
import { useAssistantStatus } from '../hooks/assistant'
import { AssistantPanel } from './AssistantPanel'

/**
 * Phase 2.9 — the assistant's entry point, fixed bottom-left on every
 * authenticated page, for all three roles (`ai.view`).
 *
 * Bottom-left rather than bottom-right so it never sits over the right-aligned
 * primary actions this console puts at the end of forms and toolbars. Hidden
 * entirely while the server says the assistant is unavailable (switched off,
 * or no model configured) — a button that can only fail is worse than none.
 */
export function AssistantLauncher() {
  const { hasPermission } = useAuth()
  const allowed = hasPermission('ai.view')
  const status = useAssistantStatus(allowed)
  const [open, setOpen] = useState(false)
  const launcherRef = useRef<HTMLButtonElement>(null)

  if (!allowed || status.data?.available !== true) return null

  function close() {
    setOpen(false)
    launcherRef.current?.focus()
  }

  return (
    <div data-print-hide>
      {open && <AssistantPanel onClose={close} />}
      <button
        ref={launcherRef}
        type="button"
        aria-expanded={open}
        aria-haspopup="dialog"
        onClick={() => (open ? close() : setOpen(true))}
        className={cn(
          'fixed bottom-4 left-4 z-30 inline-flex h-14 items-center gap-2.5 rounded-full border px-5 text-sm font-semibold shadow-[var(--shadow-overlay-sm)] transition-colors duration-150 motion-reduce:transition-none',
          open
            ? 'border-primary bg-primary-subtle text-primary-strong'
            : 'border-control-border bg-surface text-ink hover:bg-surface-sunken',
        )}
      >
        <Sparkles size={20} aria-hidden="true" />
        {open ? 'Close assistant' : 'Ask the assistant'}
      </button>
    </div>
  )
}

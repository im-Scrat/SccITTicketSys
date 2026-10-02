import { QrCode } from 'lucide-react'
import { Surface } from '@/components/ui'

/**
 * Phase 2.9 (owner decision D-C) — how a technician records work at a machine.
 *
 * Deliberately no button here: proof of work and support requests are reached
 * **only** by scanning the machine's own QR label, which is what binds a
 * submission to that machine. A shortcut in the app would let someone submit
 * without standing at it.
 */
export function ScanGuide() {
  return (
    <Surface className="flex gap-4 p-5">
      <span className="flex size-12 shrink-0 items-center justify-center rounded-md bg-primary-subtle text-primary-strong">
        <QrCode size={28} aria-hidden="true" />
      </span>
      <div className="min-w-0">
        <h2 className="text-base font-semibold text-ink-strong">At the machine</h2>
        <p className="mt-1 text-sm text-ink">
          Scan the QR label on the PC with your phone’s camera. After you sign in it opens that PC’s
          panel, where you submit proof of work or request parts and support.
        </p>
        <p className="mt-2 text-sm text-muted">
          Each assigned ticket shows the PC’s unit code and room, so you can find it.
        </p>
      </div>
    </Surface>
  )
}

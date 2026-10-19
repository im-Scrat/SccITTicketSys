import { ScanLine, X } from 'lucide-react'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui/Button'
import { safeInternalPath } from '@/features/auth/lib/postAuthDestination'
import { clearPendingScan, consumePendingScanPath, readPendingScan } from '../lib/pendingScan'

/**
 * "Continue to the PC you scanned" — shown on the dashboard after a sign-in
 * that a scanned label started (owner decision 2026-10-19).
 *
 * Sign-in always lands on the dashboard now, so the scan is offered rather
 * than followed. The stored value is a code, never a path (SDD DD-48): the
 * destination is rebuilt from a fixed template by `consumePendingScanPath`
 * and checked once more by `safeInternalPath`. Following or dismissing the
 * offer spends the scan, so it cannot reappear on the next visit to the
 * dashboard; signing out clears it too. Whether the visitor may open the PC's
 * panel is still the panel's own decision — it explains a refusal itself.
 */
export function PendingScanBanner() {
  const navigate = useNavigate()
  const [code, setCode] = useState(readPendingScan)

  if (code === null) return null

  const resume = () => {
    const path = consumePendingScanPath()
    setCode(null)
    if (path !== null) navigate(safeInternalPath(path))
  }

  const dismiss = () => {
    clearPendingScan()
    setCode(null)
  }

  return (
    <section
      aria-label="The PC you scanned"
      className="mb-6 flex flex-wrap items-center gap-x-4 gap-y-3 rounded-md border border-primary bg-primary-subtle px-4 py-3"
    >
      <ScanLine size={24} aria-hidden="true" className="shrink-0 text-primary-strong" />
      <p className="min-w-0 flex-1 basis-48 text-sm text-ink">
        You scanned a PC label before signing in.
      </p>
      <div className="flex items-center gap-2">
        <Button size="sm" variant="primary" onClick={resume}>
          Continue to the PC you scanned
        </Button>
        <Button
          size="sm"
          variant="ghost"
          onClick={dismiss}
          aria-label="Dismiss the scanned PC"
          leftIcon={<X size={18} aria-hidden="true" />}
        >
          Dismiss
        </Button>
      </div>
    </section>
  )
}

import { Camera, QrCode } from 'lucide-react'
import { useId, useState } from 'react'
import { Button } from '@/components/ui'
import { QrScannerDialog } from '@/features/qr/components/QrScannerDialog'
import { PanelHeader } from './PanelHeader'

/**
 * Phase 2.9 (owner decision D-C) — how a technician records work at a machine.
 *
 * Proof of work and support requests are reached **only** by scanning the
 * machine's own QR label, which is what binds a submission to that machine.
 *
 * Owner brief 2026-10-20 (SDD DD-117) adds a **Scan label** button that opens
 * the camera inside the app. It keeps D-C's purpose: the camera must read the
 * physical label — there is still no code field, no photo upload and no list
 * of machines to pick from — and the phone's own camera app keeps working
 * exactly as before.
 *
 * Framed like every other dashboard panel — the heading above the same bordered
 * frame — so it lines up with the panel beside it.
 */
export function ScanGuide() {
  const headingId = useId()
  const [scanning, setScanning] = useState(false)

  return (
    <section aria-labelledby={headingId} className="flex min-w-0 flex-col gap-4">
      <PanelHeader id={headingId} title="At the machine" />
      <div className="flex gap-4 rounded-lg border-2 border-border bg-surface p-6">
        <span className="flex size-12 shrink-0 items-center justify-center rounded-md bg-primary-subtle text-primary-strong">
          <QrCode size={28} aria-hidden="true" />
        </span>
        <div className="min-w-0">
          <p className="text-sm text-ink">
            Scan the QR label on the PC with your phone’s camera, or with Scan label below. After
            you sign in it opens that PC’s panel, where you submit proof of work or request parts
            and support.
          </p>
          <p className="mt-2 text-sm text-muted">
            Each assigned ticket shows the PC’s unit code and room, so you can find it.
          </p>
          <Button className="mt-4" variant="secondary" onClick={() => setScanning(true)}>
            <Camera size={20} aria-hidden="true" />
            Scan label
          </Button>
        </div>
      </div>

      {scanning && <QrScannerDialog open={scanning} onClose={() => setScanning(false)} />}
    </section>
  )
}

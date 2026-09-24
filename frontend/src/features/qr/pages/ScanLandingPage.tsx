import { useEffect, useRef, useState } from 'react'
import { Navigate, useParams } from 'react-router-dom'
import { Container, Spinner, Surface } from '@/components/ui'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { ScanRefusalNotice } from '../components/ScanRefusalNotice'
import { useScanCode } from '../hooks/mutations'
import { type Refusal, refusalFor } from '../lib/refusals'
import { rememberPendingScan, resumePath } from '../lib/pendingScan'

/**
 * Where a printed label lands (SRS FR-QR-005/009/010/011; SDD DD-47/DD-48).
 *
 * A sticker carries `{APP_URL}/qr/{code}`, so a phone camera opens this route
 * with no native app. The page is **public on purpose**: FR-QR-010 requires an
 * unauthenticated scan to be recorded and answered without disclosing anything,
 * and a route guard would answer before the scan was ever logged.
 *
 * ── It shows almost nothing, and that is the design ────────────────────────
 *
 * This page has no content of its own — not a name, not a location, not a
 * status. It POSTs the code, and the server replies with one of three routing
 * decisions. Anything more would be the disclosure FR-QR-010 forbids, and there
 * is nothing to disclose anyway: the scan endpoint never returns machine data,
 * even to an authorized caller.
 *
 * ── Carrying the destination ───────────────────────────────────────────────
 *
 * An unauthenticated visitor is sent to sign in with the **code** stored, never
 * a URL (FR-QR-011). `resumePath()` rebuilds the destination afterwards from a
 * fixed template, so no query string, referrer or stored value can send anyone
 * off this origin.
 */
export default function ScanLandingPage() {
  const { code = '' } = useParams<{ code: string }>()
  const scan = useScanCode()

  const [destination, setDestination] = useState<string | null>(null)
  const [refusal, setRefusal] = useState<Refusal | null>(null)

  useDocumentMeta({ title: 'Scanned equipment' })

  /*
   * React 18's development StrictMode mounts effects twice. A scan is a logged
   * physical event, so a second POST would put a phantom row in the scan
   * history — the ref makes the call happen once per mounted code.
   */
  const requested = useRef<string | null>(null)

  useEffect(() => {
    if (code === '' || requested.current === code) return

    requested.current = code

    scan
      .mutateAsync(code)
      .then((outcome) => {
        if (outcome.next === 'panel') {
          setDestination(resumePath(code))

          return
        }

        if (outcome.next === 'sign-in') {
          // The code, not a path. Rebuilt by the application after sign-in.
          rememberPendingScan(code)
          setDestination('/sign-in')

          return
        }

        setRefusal(refusalFor(outcome.reason))
      })
      .catch(() => {
        setRefusal({
          title: 'The label could not be checked',
          body: 'The system could not be reached just now, so this scan has not been recorded.',
          action: 'Check your connection and scan the label again.',
        })
      })
    // `scan` is a stable mutation object; re-running on its identity would
    // re-scan on every render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [code])

  if (destination !== null) {
    return <Navigate to={destination} replace />
  }

  return (
    <main className="flex min-h-dvh items-center bg-bg py-16">
      <Container>
        {refusal !== null ? (
          <div className="mx-auto max-w-2xl">
            <ScanRefusalNotice refusal={refusal} />
          </div>
        ) : (
          <Surface className="mx-auto flex max-w-2xl flex-col items-center gap-4 p-10 text-center">
            <Spinner />
            <p className="text-sm font-medium text-ink">Checking this label…</p>
            <p className="max-w-[45ch] text-sm text-muted">
              Equipment details open once you are signed in and the job is yours.
            </p>
          </Surface>
        )}
      </Container>
    </main>
  )
}

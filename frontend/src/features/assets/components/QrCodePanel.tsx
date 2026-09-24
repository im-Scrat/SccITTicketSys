import { Printer, QrCode as QrIcon, RefreshCw, Ban } from 'lucide-react'
import { useState } from 'react'
import { Alert, Badge, Button, ConfirmDialog, EmptyState, Skeleton } from '@/components/ui'
import { getErrorMessage } from '@/features/auth/lib/serverErrors'
import { formatDateTime } from '@/lib/datetime'
import { type AssetKind, fetchQrPrint } from '../api/assetsApi'
import { useGenerateQr, useRegenerateQr, useRevokeQr } from '../hooks/mutations'
import { useQrCodes } from '../hooks/queries'

/**
 * QR label management for one asset or PC (SRS FR-QR-001..004/007).
 *
 * The label history is shown in full, revoked codes included — that is the point
 * of revoking rather than deleting: the sticker that was on the machine last
 * term is still explicable, and its scan log still resolves.
 *
 * Printing opens a dedicated print window rather than printing the page, so a
 * label comes out as a label and not as a screenshot of the app.
 */
export function QrCodePanel({
  kind,
  id,
  canManage,
}: {
  kind: AssetKind
  id: string
  canManage: boolean
}) {
  const { data, isLoading } = useQrCodes(kind, id)
  const generate = useGenerateQr(kind, id)
  const regenerate = useRegenerateQr(kind, id)
  const revoke = useRevokeQr(kind, id)

  const [error, setError] = useState<string | null>(null)
  const [confirming, setConfirming] = useState<'regenerate' | 'revoke' | null>(null)

  const active = data?.meta.active ?? null
  const svg = data?.meta.svg ?? null

  const run = async (action: () => Promise<unknown>) => {
    setError(null)
    try {
      await action()
    } catch (caught) {
      setError(getErrorMessage(caught))
    } finally {
      setConfirming(null)
    }
  }

  const print = async () => {
    setError(null)
    try {
      const payload = await fetchQrPrint(kind, id, 640)
      openPrintWindow(payload)
    } catch (caught) {
      setError(getErrorMessage(caught))
    }
  }

  if (isLoading) return <Skeleton className="h-72 rounded-lg" />

  return (
    <div className="flex flex-col gap-8">
      {error && <Alert tone="error">{error}</Alert>}

      {active && svg ? (
        <div className="flex flex-col items-start gap-8 lg:flex-row">
          <div className="rounded-lg border-2 border-border bg-white p-6">
            {/* The SVG arrives as a data URI, so the label needs no second
                request and prints crisply at any physical size. */}
            <img src={svg} alt={`QR code ${active.code}`} className="size-56" />
          </div>

          <div className="flex min-w-0 flex-1 flex-col gap-5">
            <div>
              <p className="text-xs font-semibold uppercase tracking-wide text-muted">
                Active code
              </p>
              <p className="mt-1 font-mono text-lg font-bold text-ink-strong">{active.code}</p>
            </div>

            {active.location_label && (
              <div>
                <p className="text-xs font-semibold uppercase tracking-wide text-muted">
                  Printed location
                </p>
                <p className="mt-1 text-base text-ink">{active.location_label}</p>
              </div>
            )}

            <div>
              <p className="text-xs font-semibold uppercase tracking-wide text-muted">Generated</p>
              <p className="mt-1 text-base text-ink">{formatDateTime(active.generated_at)}</p>
            </div>

            <div className="flex flex-wrap gap-3">
              <Button variant="secondary" onClick={print}>
                <Printer size={32} aria-hidden="true" />
                Print label
              </Button>
              {canManage && (
                <>
                  <Button variant="secondary" onClick={() => setConfirming('regenerate')}>
                    <RefreshCw size={32} aria-hidden="true" />
                    Regenerate
                  </Button>
                  <Button variant="danger" onClick={() => setConfirming('revoke')}>
                    <Ban size={32} aria-hidden="true" />
                    Revoke
                  </Button>
                </>
              )}
            </div>
          </div>
        </div>
      ) : (
        <EmptyState
          title="No QR label yet"
          description="Generate one to label this equipment for on-site identification."
          action={
            canManage ? (
              <Button onClick={() => void run(() => generate.mutateAsync())}>
                <QrIcon size={32} aria-hidden="true" />
                Generate QR code
              </Button>
            ) : undefined
          }
        />
      )}

      {(data?.data.length ?? 0) > 1 && (
        <section>
          <h3 className="text-base font-bold text-ink-strong">Label history</h3>
          <ul className="mt-3 flex flex-col divide-y divide-border">
            {data?.data.map((code) => (
              <li key={code.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                <span className="font-mono text-sm text-ink">{code.code}</span>
                <span className="flex items-center gap-3">
                  <Badge tone={code.is_active ? 'success' : 'neutral'}>{code.status_label}</Badge>
                  <span className="text-xs text-muted">{formatDateTime(code.generated_at)}</span>
                </span>
              </li>
            ))}
          </ul>
        </section>
      )}

      <ConfirmDialog
        open={confirming === 'regenerate'}
        onClose={() => setConfirming(null)}
        onConfirm={() => void run(() => regenerate.mutateAsync())}
        title="Regenerate this QR code?"
        confirmLabel="Regenerate"
      >
        A new code is issued and the current one is revoked. Any label already printed and stuck to
        the equipment will stop matching, so print and replace it. Past scans stay on record.
      </ConfirmDialog>

      <ConfirmDialog
        open={confirming === 'revoke'}
        onClose={() => setConfirming(null)}
        onConfirm={() => void run(() => revoke.mutateAsync())}
        title="Revoke this QR code?"
        confirmLabel="Revoke"
        tone="danger"
      >
        The code stops being valid and no replacement is issued. Past scans stay on record.
      </ConfirmDialog>
    </div>
  )
}

/**
 * Open a minimal print document containing just the label. Writing a standalone
 * document is what keeps app chrome, navigation and the dark theme off a printed
 * sticker.
 */
function openPrintWindow(payload: {
  meta: { svg: string; label: string; identifier: string; location: string | null }
  data: { code: string }
}) {
  const printWindow = window.open('', '_blank', 'width=520,height=680')
  if (!printWindow) return

  const { svg, label, identifier, location } = payload.meta

  printWindow.document.write(`<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>QR label — ${escapeHtml(identifier)}</title>
<style>
  @page { margin: 12mm; }
  body {
    font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
    color: #000; background: #fff;
    display: flex; flex-direction: column; align-items: center; gap: 12px;
    margin: 0; padding: 16px;
  }
  img { width: 260px; height: 260px; }
  .identifier { font-family: ui-monospace, monospace; font-size: 22px; font-weight: 700; }
  .name { font-size: 18px; font-weight: 600; text-align: center; }
  .location { font-size: 15px; color: #333; text-align: center; }
  .code { font-family: ui-monospace, monospace; font-size: 13px; color: #333; }
</style>
</head>
<body>
  <img src="${svg}" alt="QR code ${escapeHtml(payload.data.code)}">
  <div class="identifier">${escapeHtml(identifier)}</div>
  <div class="name">${escapeHtml(label)}</div>
  ${location ? `<div class="location">${escapeHtml(location)}</div>` : ''}
  <div class="code">${escapeHtml(payload.data.code)}</div>
</body>
</html>`)

  printWindow.document.close()
  printWindow.focus()
  printWindow.print()
}

/** The label text is server data, but it still goes through innerHTML here. */
function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

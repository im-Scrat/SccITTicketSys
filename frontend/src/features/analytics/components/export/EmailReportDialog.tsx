import { isAxiosError } from 'axios'
import { Mail, X } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Alert, Badge, Button, Checkbox, LiveRegion, Modal, Textarea } from '@/components/ui'
import { useCheckRecipients, useExportStatus, useSendReportEmail } from '../../hooks/exports'
import type { ExportFormat, RecipientCheck, ReportParams, ReportType } from '../../types'
import { FormatChoice } from './FormatChoice'

interface EmailReportDialogProps {
  open: boolean
  onClose: () => void
  reportType: ReportType
  params: ReportParams
  canIncludeRecords: boolean
}

const MAX_RECIPIENTS = 10

/** Addresses as typed: split on commas, semicolons, spaces or new lines; lower-cased, de-duplicated. */
function parseRecipients(text: string): string[] {
  return Array.from(
    new Set(
      text
        .split(/[\s,;]+/)
        .map((part) => part.trim().toLowerCase())
        .filter(Boolean),
    ),
  )
}

function serverMessage(error: unknown): string | null {
  if (!isAxiosError(error)) return null
  if (error.response?.status === 429)
    return 'Too many report emails in the last hour. Try again later.'
  const errors = (error.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors
  return errors ? (Object.values(errors)[0]?.[0] ?? null) : null
}

/**
 * Email a report to any address, with safeguards (Phase 2.10 R3, owner
 * decision D1, SDD DD-92).
 *
 * Three steps, so nothing leaves by accident:
 *  1. **Compose** — addresses (up to ten), format, records.
 *  2. **Check** — the server's own account of what will be sent (identifiers
 *     and figures only, never ticket titles or descriptions), which addresses
 *     are outside the school, and — while outgoing mail goes to a capture
 *     sink — that recipients will not actually receive it. Outside addresses
 *     need an explicit tick, and the server refuses unless that list matches
 *     its own.
 *  3. **Queued** — one line per recipient: Queued, Sent to mail server, or
 *     Failed. Never "delivered": the application cannot know that.
 */
export function EmailReportDialog({
  open,
  onClose,
  reportType,
  params,
  canIncludeRecords,
}: EmailReportDialogProps) {
  const [step, setStep] = useState<'compose' | 'check' | 'queued'>('compose')
  const [text, setText] = useState('')
  const [format, setFormat] = useState<ExportFormat>('pdf')
  const [includeRecords, setIncludeRecords] = useState(false)
  const [preview, setPreview] = useState<RecipientCheck | null>(null)
  const [confirmed, setConfirmed] = useState(false)
  const [exportId, setExportId] = useState<string | null>(null)

  const recipients = useMemo(() => parseRecipients(text), [text])
  const check = useCheckRecipients()
  const send = useSendReportEmail()
  const status = useExportStatus(exportId)

  const reset = () => {
    setStep('compose')
    setPreview(null)
    setConfirmed(false)
    setExportId(null)
    check.reset()
    send.reset()
  }

  const close = () => {
    reset()
    setText('')
    onClose()
  }

  const request = { report_type: reportType, params, format, include_records: includeRecords }

  const toCheck = () =>
    check.mutate(
      { ...request, recipients },
      {
        onSuccess: (result) => {
          setPreview(result)
          setConfirmed(false)
          setStep('check')
        },
      },
    )

  const toSend = () =>
    send.mutate(
      { ...request, recipients, confirmed_external: preview?.external ?? [] },
      {
        onSuccess: (item) => {
          setExportId(item.id)
          setStep('queued')
        },
      },
    )

  const removeRecipient = (address: string) =>
    setText(recipients.filter((r) => r !== address).join(', '))

  const external = preview?.external ?? []
  const deliveries = status.data?.deliveries ?? send.data?.deliveries ?? []
  const tooMany = recipients.length > MAX_RECIPIENTS
  const exportFailed = status.data?.status === 'failed'

  const footer =
    step === 'compose' ? (
      <>
        <Button variant="ghost" size="sm" onClick={close}>
          Cancel
        </Button>
        <Button
          size="sm"
          onClick={toCheck}
          loading={check.isPending}
          disabled={recipients.length === 0 || tooMany}
        >
          Continue
        </Button>
      </>
    ) : step === 'check' ? (
      <>
        <Button
          variant="ghost"
          size="sm"
          onClick={() => setStep('compose')}
          disabled={send.isPending}
        >
          Back
        </Button>
        <Button
          size="sm"
          onClick={toSend}
          loading={send.isPending}
          disabled={
            (external.length > 0 && !confirmed) ||
            (preview?.remaining_today ?? 0) < recipients.length
          }
          leftIcon={<Mail size={16} aria-hidden="true" />}
        >
          Send to {recipients.length} {recipients.length === 1 ? 'recipient' : 'recipients'}
        </Button>
      </>
    ) : (
      <Button variant="secondary" size="sm" onClick={close}>
        Close
      </Button>
    )

  return (
    <Modal
      open={open}
      onClose={close}
      title="Email report"
      description="Send this report, as it is filtered now, as an attachment."
      size="lg"
      footer={footer}
    >
      <div className="flex flex-col gap-6">
        {step === 'compose' && (
          <>
            <div className="flex flex-col gap-2">
              <label htmlFor="email-recipients" className="text-sm font-semibold text-ink">
                Recipients
              </label>
              <Textarea
                id="email-recipients"
                value={text}
                onChange={(event) => setText(event.target.value)}
                rows={3}
                placeholder="name@school.edu.ph, another@example.org"
                aria-describedby="email-recipients-hint"
              />
              <span id="email-recipients-hint" className="text-xs text-muted">
                Up to {MAX_RECIPIENTS} addresses, separated by commas or new lines.
              </span>
            </div>

            {recipients.length > 0 && (
              <ul className="flex flex-wrap gap-2" aria-label="Recipients">
                {recipients.map((address) => (
                  <li
                    key={address}
                    className="flex items-center gap-1 rounded-full border border-border bg-surface-sunken py-1 pl-3 pr-1 text-sm text-ink"
                  >
                    <span className="break-all">{address}</span>
                    <button
                      type="button"
                      onClick={() => removeRecipient(address)}
                      aria-label={`Remove ${address}`}
                      className="flex size-8 items-center justify-center rounded-full text-muted hover:bg-surface hover:text-ink"
                    >
                      <X size={16} aria-hidden="true" />
                    </button>
                  </li>
                ))}
              </ul>
            )}
            {tooMany && (
              <Alert tone="warning">Send to at most {MAX_RECIPIENTS} addresses at a time.</Alert>
            )}

            <FormatChoice value={format} onChange={setFormat} formats={['pdf', 'docx', 'xlsx']} />

            {canIncludeRecords && (
              <div className="flex flex-col gap-1">
                <Checkbox
                  label="Include ticket records (identifiers only)"
                  checked={includeRecords}
                  onChange={(event) => setIncludeRecords(event.target.checked)}
                />
                <p className="pl-8 text-xs text-muted">
                  Ticket numbers, categories, statuses, room codes and dates. Never titles,
                  descriptions or comments.
                </p>
              </div>
            )}

            {check.isError && (
              <Alert tone="error">
                {serverMessage(check.error) ?? 'The addresses could not be checked.'}
              </Alert>
            )}
          </>
        )}

        {step === 'check' && preview && (
          <>
            {!preview.delivers_externally && (
              <Alert tone="warning" title="This server captures outgoing email for testing">
                Recipients will not receive it until the school's mail server is configured. The
                message can be inspected in the mail capture tool.
              </Alert>
            )}

            <section aria-labelledby="email-contents" className="flex flex-col gap-2">
              <h3 id="email-contents" className="text-base font-semibold text-ink-strong">
                What will be sent
              </h3>
              <p className="text-sm text-ink">
                {preview.contents.report}, as {preview.contents.format}.
              </p>
              <ul className="list-disc pl-6 text-sm text-ink">
                {preview.contents.includes.map((line) => (
                  <li key={line}>{line}</li>
                ))}
              </ul>
              <ul className="list-disc pl-6 text-sm text-muted">
                {preview.contents.excludes.map((line) => (
                  <li key={line}>{line}</li>
                ))}
              </ul>
            </section>

            <section aria-labelledby="email-to" className="flex flex-col gap-2">
              <h3 id="email-to" className="text-base font-semibold text-ink-strong">
                To
              </h3>
              <ul className="flex flex-col divide-y divide-border rounded-md border border-border">
                {preview.recipients.map((r) => (
                  <li
                    key={r.address}
                    className="flex flex-wrap items-center justify-between gap-2 px-4 py-2"
                  >
                    <span className="text-sm text-ink break-all">{r.address}</span>
                    {r.external && <Badge tone="warning">Outside the school</Badge>}
                  </li>
                ))}
              </ul>
              <p className="text-xs text-muted">
                {preview.remaining_today} of {preview.daily_cap} recipients left today.
              </p>
            </section>

            {external.length > 0 && (
              <Checkbox
                label={`I confirm ${external.length === 1 ? 'this address outside the school' : `these ${external.length} addresses outside the school`} may receive this report`}
                checked={confirmed}
                onChange={(event) => setConfirmed(event.target.checked)}
              />
            )}

            {send.isError && (
              <Alert tone="error">
                {serverMessage(send.error) ?? 'The email could not be queued.'}
              </Alert>
            )}
          </>
        )}

        {step === 'queued' && (
          <>
            <Alert
              tone={exportFailed ? 'warning' : 'success'}
              title={
                exportFailed
                  ? 'The attachment could not be prepared'
                  : `Queued for ${deliveries.length} ${deliveries.length === 1 ? 'recipient' : 'recipients'}`
              }
            >
              {exportFailed
                ? 'Nothing was sent. Try again in a moment.'
                : '"Sent to mail server" means the school\'s mail server accepted the message; delivery to each mailbox is up to it.'}
            </Alert>
            <ul className="flex flex-col divide-y divide-border rounded-md border border-border">
              {deliveries.map((d) => (
                <li
                  key={d.recipient}
                  className="flex flex-wrap items-center justify-between gap-2 px-4 py-2"
                >
                  <span className="text-sm text-ink break-all">{d.recipient}</span>
                  <Badge
                    tone={
                      d.status === 'sent'
                        ? 'success'
                        : d.status === 'failed'
                          ? 'warning'
                          : 'neutral'
                    }
                  >
                    {d.status_label}
                  </Badge>
                </li>
              ))}
            </ul>
          </>
        )}

        <LiveRegion
          message={
            step === 'check'
              ? `${external.length} of ${recipients.length} addresses are outside the school.`
              : step === 'queued'
                ? `Queued for ${deliveries.length} recipients.`
                : ''
          }
        />
      </div>
    </Modal>
  )
}

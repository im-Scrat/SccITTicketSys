import { isAxiosError } from 'axios'
import { Download } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Alert, Button, Checkbox, LiveRegion, Modal, ProgressBar, Select } from '@/components/ui'
import { downloadExport } from '../../api/reportsApi'
import { useCreateExport, useExportStatus } from '../../hooks/exports'
import type { ExportFormat, ReportParams, ReportType } from '../../types'
import { FormatChoice } from './FormatChoice'
import { RecentExportsList } from './RecentExportsList'

interface ExportDialogProps {
  open: boolean
  onClose: () => void
  reportType: ReportType
  params: ReportParams
  /** The viewer may include ticket records (tickets.export). */
  canIncludeRecords: boolean
}

/**
 * Export a report as PDF, Word, Excel or CSV (Phase 2.10 R3; SRS FR-RPT-004).
 *
 * Only the options that apply are shown: paper and orientation for the paged
 * formats, records only for someone allowed to export them. The file is
 * prepared on the server's bulk worker — the dialog shows progress, downloads
 * it the moment it is ready, and offers it again from "Recent exports" for
 * three days. It never claims a file it does not have: a failure says so, in
 * the server's words where it gave any.
 */
export function ExportDialog({
  open,
  onClose,
  reportType,
  params,
  canIncludeRecords,
}: ExportDialogProps) {
  const [format, setFormat] = useState<ExportFormat>('pdf')
  const [paper, setPaper] = useState<'a4' | 'letter'>('a4')
  const [orientation, setOrientation] = useState<'portrait' | 'landscape'>('portrait')
  const [includeRecords, setIncludeRecords] = useState(false)
  const [includeNarrative, setIncludeNarrative] = useState(false)
  const [exportId, setExportId] = useState<string | null>(null)
  const [downloadError, setDownloadError] = useState<string | null>(null)
  const downloaded = useRef<string | null>(null)

  const create = useCreateExport()
  const status = useExportStatus(exportId)
  const item = status.data
  const paged = format === 'pdf' || format === 'docx'

  // Excel and CSV exist to carry the records; the paged formats leave them out
  // by default (they would be cut at about 600 rows).
  useEffect(() => {
    setIncludeRecords(canIncludeRecords && (format === 'xlsx' || format === 'csv'))
  }, [format, canIncludeRecords])

  // Download once, the moment the file is ready.
  useEffect(() => {
    if (item?.status !== 'completed' || downloaded.current === item.id) return
    downloaded.current = item.id
    downloadExport(item).catch((error: unknown) =>
      setDownloadError(error instanceof Error ? error.message : 'The download did not start.'),
    )
  }, [item])

  const reset = () => {
    setExportId(null)
    setDownloadError(null)
    create.reset()
  }

  const close = () => {
    reset()
    onClose()
  }

  const submit = () => {
    setDownloadError(null)
    create.mutate(
      {
        report_type: reportType,
        params,
        format,
        include_records: includeRecords,
        ...(reportType === 'overall' ? { include_narrative: includeNarrative } : {}),
        ...(paged ? { paper, orientation } : {}),
      },
      { onSuccess: (created) => setExportId(created.id) },
    )
  }

  const preparing = create.isPending || item?.status === 'pending' || item?.status === 'processing'
  const failed = item?.status === 'failed'
  const ready = item?.status === 'completed'
  const requestError = create.error
    ? isAxiosError(create.error) && create.error.response?.status === 429
      ? 'Too many exports in the last hour. Try again later.'
      : 'The export could not be started. Try again.'
    : null

  const announcement = ready
    ? `${item.filename ?? 'The file'} is ready and downloading.`
    : failed
      ? 'The file could not be prepared.'
      : preparing
        ? 'Preparing the file.'
        : ''

  return (
    <Modal
      open={open}
      onClose={close}
      title="Export report"
      description="A file of exactly what this report shows, with its filters and time zone."
      size="lg"
      footer={
        exportId === null ? (
          <>
            <Button variant="ghost" size="sm" onClick={close}>
              Cancel
            </Button>
            <Button
              size="sm"
              loading={create.isPending}
              onClick={submit}
              leftIcon={<Download size={16} aria-hidden="true" />}
            >
              Prepare file
            </Button>
          </>
        ) : (
          <>
            <Button variant="ghost" size="sm" onClick={reset} disabled={preparing}>
              Export another
            </Button>
            <Button variant="secondary" size="sm" onClick={close}>
              Close
            </Button>
          </>
        )
      }
    >
      <div className="flex flex-col gap-6">
        {exportId === null ? (
          <>
            <FormatChoice value={format} onChange={setFormat} />

            {paged && (
              <div className="grid gap-4 sm:grid-cols-2">
                <label className="flex flex-col gap-2">
                  <span className="text-sm font-semibold text-ink">Paper</span>
                  <Select
                    value={paper}
                    onChange={(event) => setPaper(event.target.value as 'a4' | 'letter')}
                  >
                    <option value="a4">A4</option>
                    <option value="letter">Letter</option>
                  </Select>
                </label>
                <label className="flex flex-col gap-2">
                  <span className="text-sm font-semibold text-ink">Orientation</span>
                  <Select
                    value={orientation}
                    onChange={(event) =>
                      setOrientation(event.target.value as 'portrait' | 'landscape')
                    }
                  >
                    <option value="portrait">Portrait</option>
                    <option value="landscape">Landscape</option>
                  </Select>
                </label>
              </div>
            )}

            {canIncludeRecords ? (
              <div className="flex flex-col gap-1">
                <Checkbox
                  label="Include ticket records"
                  checked={includeRecords}
                  onChange={(event) => setIncludeRecords(event.target.checked)}
                />
                <p className="pl-8 text-xs text-muted">
                  {paged
                    ? 'One row per ticket. PDF and Word carry up to about 600 rows; Excel carries every record.'
                    : 'One row per ticket counted in "Reported".'}
                </p>
              </div>
            ) : (
              <p className="text-sm text-muted">
                Ticket records are not included: exporting them needs the tickets export permission.
              </p>
            )}

            {reportType === 'overall' && (
              <div className="flex flex-col gap-1">
                <Checkbox
                  label="Include the AI interpretation"
                  checked={includeNarrative}
                  onChange={(event) => setIncludeNarrative(event.target.checked)}
                />
                <p className="pl-8 text-xs text-muted">
                  Only if one was generated for exactly these figures; otherwise the document says
                  so. It is labelled advisory, beside the recorded facts.
                </p>
              </div>
            )}

            {requestError && <Alert tone="error">{requestError}</Alert>}
          </>
        ) : (
          <div className="flex flex-col gap-4">
            {preparing && (
              <ProgressBar
                label="Preparing your file"
                valueText="This usually takes a few seconds"
              />
            )}
            {ready && (
              <Alert tone="success" title="Your file is ready">
                <span className="flex flex-col items-start gap-3">
                  <span>
                    {item.filename}
                    {item.truncated
                      ? ' — some lists were cut to fit; Excel carries every row.'
                      : ''}
                  </span>
                  <Button
                    variant="secondary"
                    size="sm"
                    leftIcon={<Download size={16} aria-hidden="true" />}
                    onClick={() =>
                      downloadExport(item).catch((error: unknown) =>
                        setDownloadError(
                          error instanceof Error ? error.message : 'The download did not start.',
                        ),
                      )
                    }
                  >
                    Download again
                  </Button>
                </span>
              </Alert>
            )}
            {failed && (
              <Alert tone="warning" title="The file could not be prepared">
                Nothing was saved. Try again; if it keeps failing, choose a shorter period or leave
                the records out.
              </Alert>
            )}
            {downloadError && <Alert tone="warning">{downloadError}</Alert>}
          </div>
        )}

        <details className="rounded-md border border-border px-4 py-3">
          <summary className="cursor-pointer text-sm font-semibold text-ink">
            Recent exports
          </summary>
          <div className="mt-3">
            <RecentExportsList enabled={open} />
          </div>
        </details>

        <LiveRegion message={announcement} />
      </div>
    </Modal>
  )
}

import { Download, Mail, Printer } from 'lucide-react'
import { useState } from 'react'
import { Button, ButtonLink } from '@/components/ui'
import { useReportsAccess } from '../../hooks/useReportsAccess'
import type { ReportParams, ReportType } from '../../types'
import { EmailReportDialog } from './EmailReportDialog'
import { ExportDialog } from './ExportDialog'

interface ReportActionsProps {
  reportType: ReportType
  params: ReportParams
  /** The print route for this report ("/app/dashboard/print"). */
  printPath: string
}

/**
 * Export, Email and Print for a report (Phase 2.10 R3). All three are
 * secondary — the page's one primary action, if any, is its own — and each
 * carries text as well as an icon. Hidden entirely without `reports.export`:
 * an action that can only fail is not offered.
 */
export function ReportActions({ reportType, params, printPath }: ReportActionsProps) {
  const { canExport, canExportRecords } = useReportsAccess()
  const [dialog, setDialog] = useState<'export' | 'email' | null>(null)

  if (!canExport) return null

  const canIncludeRecords = canExportRecords === true
  const query = new URLSearchParams(
    Object.entries(params).filter(([, v]) => v !== undefined && v !== '') as Array<
      [string, string]
    >,
  )
  if (canIncludeRecords) query.set('include_records', '1')
  if (reportType === 'overall') query.set('include_narrative', '1')
  query.set('autoprint', '1')

  return (
    <>
      <div className="flex flex-wrap items-center gap-2" data-print-hide>
        <Button
          variant="secondary"
          size="sm"
          leftIcon={<Download size={16} aria-hidden="true" />}
          onClick={() => setDialog('export')}
        >
          Export
        </Button>
        <Button
          variant="secondary"
          size="sm"
          leftIcon={<Mail size={16} aria-hidden="true" />}
          onClick={() => setDialog('email')}
        >
          Email
        </Button>
        <ButtonLink
          variant="secondary"
          size="sm"
          to={`${printPath}?${query.toString()}`}
          leftIcon={<Printer size={16} aria-hidden="true" />}
        >
          Print
        </ButtonLink>
      </div>

      {/* Mounted only while open: a closed dialog has nothing to fetch or poll. */}
      {dialog === 'export' && (
        <ExportDialog
          open
          onClose={() => setDialog(null)}
          reportType={reportType}
          params={params}
          canIncludeRecords={canIncludeRecords}
        />
      )}
      {dialog === 'email' && (
        <EmailReportDialog
          open
          onClose={() => setDialog(null)}
          reportType={reportType}
          params={params}
          canIncludeRecords={canIncludeRecords}
        />
      )}
    </>
  )
}

import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Printer } from 'lucide-react'
import { useEffect, useMemo, useRef } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Alert, Button, ButtonLink, Skeleton } from '@/components/ui'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { fetchPrintDocument } from '../api/reportsApi'
import { PrintableReport } from '../components/print/PrintableReport'
import { OVERALL_PARAM_KEYS, REPORT_PARAM_KEYS } from '../hooks/useReportFilters'
import type { OverallParams, ReportType } from '../types'

interface ReportPrintPageProps {
  reportType: ReportType
  /** Where "Back" goes, with the same filters. */
  backPath: string
  backLabel: string
}

/**
 * The print view of a report (Phase 2.10 R3): outside the app layout, always
 * light, the same content as the PDF. Opened from a report's Print button with
 * its filters in the URL and `autoprint=1`; once the document and the fonts
 * are ready it opens the browser's print dialog — and first removes `autoprint`
 * with a replace navigation, so a reload or Back never prints again.
 *
 * Paper size is the print dialog's choice: no `@page size` is forced.
 * Fetched once (a POST, audited as ReportPrinted), never refetched on focus.
 */
export default function ReportPrintPage({ reportType, backPath, backLabel }: ReportPrintPageProps) {
  const [searchParams] = useSearchParams()
  const navigate = useNavigate()
  const printed = useRef(false)

  const params = useMemo<OverallParams>(() => {
    const out: OverallParams = {}
    // The overall report carries its own filters (equipment, repair class…) too.
    const keys = reportType === 'overall' ? OVERALL_PARAM_KEYS : REPORT_PARAM_KEYS
    for (const key of keys) {
      const value = searchParams.get(key)
      if (value) out[key] = value
    }
    return out
  }, [searchParams, reportType])
  const includeRecords = searchParams.get('include_records') === '1'
  const includeNarrative = searchParams.get('include_narrative') === '1'
  const autoprint = searchParams.get('autoprint') === '1'

  const document = useQuery({
    queryKey: ['report-print', reportType, params, includeRecords, includeNarrative],
    queryFn: () =>
      fetchPrintDocument({
        report_type: reportType,
        params,
        include_records: includeRecords,
        include_narrative: includeNarrative,
      }),
    staleTime: Infinity,
    refetchOnWindowFocus: false,
    retry: false,
  })

  useDocumentMeta({ title: document.data ? `${document.data.title} — print` : 'Print report' })

  const backQuery = new URLSearchParams(params as Record<string, string>).toString()

  useEffect(() => {
    if (!autoprint || !document.data || printed.current) return
    printed.current = true

    const next = new URLSearchParams(searchParams)
    next.delete('autoprint')
    navigate({ search: next.toString() }, { replace: true })

    void (globalThis.document.fonts?.ready ?? Promise.resolve()).then(() => {
      // One frame for the chart images to lay out before the dialog snapshots the page.
      requestAnimationFrame(() => window.print())
    })
  }, [autoprint, document.data, navigate, searchParams])

  return (
    <div
      data-theme="light"
      className="min-h-screen bg-surface px-4 py-6 text-ink sm:px-8 print:p-0"
    >
      <div
        data-print-hide
        className="mx-auto mb-6 flex max-w-[52rem] flex-wrap items-center justify-between gap-3 border-b border-border pb-4"
      >
        <ButtonLink
          variant="ghost"
          size="sm"
          to={`${backPath}${backQuery ? `?${backQuery}` : ''}`}
          leftIcon={<ArrowLeft size={16} aria-hidden="true" />}
        >
          {backLabel}
        </ButtonLink>
        <div className="flex flex-wrap items-center gap-3">
          <span className="text-xs text-muted">Choose A4 or Letter in the print dialog.</span>
          <Button
            size="sm"
            onClick={() => window.print()}
            disabled={!document.data}
            leftIcon={<Printer size={16} aria-hidden="true" />}
          >
            Print
          </Button>
        </div>
      </div>

      {document.isError ? (
        <div className="mx-auto max-w-[52rem]">
          <Alert tone="error" title="The report could not be prepared for printing">
            Go back and try again.
          </Alert>
        </div>
      ) : !document.data ? (
        <div className="mx-auto flex max-w-[52rem] flex-col gap-4" aria-busy="true">
          <Skeleton className="h-10 w-2/3" />
          <Skeleton className="h-40" />
          <Skeleton className="h-72" />
        </div>
      ) : (
        <PrintableReport document={document.data} />
      )}
    </div>
  )
}

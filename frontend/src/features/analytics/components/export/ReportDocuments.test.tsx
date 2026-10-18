import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { RecipientCheck, ReportExport } from '../../types'
import { EmailReportDialog } from './EmailReportDialog'
import { ExportDialog } from './ExportDialog'
import { ReportActions } from './ReportActions'

const api = vi.hoisted(() => ({
  createExport: vi.fn(),
  fetchExport: vi.fn(),
  fetchRecentExports: vi.fn(),
  downloadExport: vi.fn(),
  checkRecipients: vi.fn(),
  sendReportEmail: vi.fn(),
}))
vi.mock('../../api/reportsApi', () => api)

const access = vi.hoisted(() => ({
  value: { canView: true, canExport: true, canExportRecords: true },
}))
vi.mock('../../hooks/useReportsAccess', () => ({ useReportsAccess: () => access.value }))

function exportItem(overrides: Partial<ReportExport> = {}): ReportExport {
  return {
    id: 'e-1',
    report_type: 'ticket_overview',
    report_label: 'Ticket overview',
    format: 'pdf',
    format_label: 'PDF',
    purpose: 'download',
    profile: 'full',
    status: 'pending',
    status_label: 'Waiting',
    filename: null,
    size_bytes: null,
    row_count: null,
    truncated: false,
    error_code: null,
    created_at: '2026-10-18T02:00:00Z',
    completed_at: null,
    expires_at: null,
    download_url: null,
    ...overrides,
  }
}

function renderWith(ui: ReactNode) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  access.value = { canView: true, canExport: true, canExportRecords: true }
  api.fetchRecentExports.mockResolvedValue([])
  api.downloadExport.mockResolvedValue(undefined)
})

describe('ExportDialog', () => {
  it('offers paper and orientation only for the paged formats', async () => {
    renderWith(
      <ExportDialog
        open
        onClose={() => {}}
        reportType="ticket_overview"
        params={{}}
        canIncludeRecords
      />,
    )

    expect(screen.getByRole('combobox', { name: 'Paper' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('radio', { name: /Excel/ }))
    expect(screen.queryByRole('combobox', { name: 'Paper' })).not.toBeInTheDocument()
    // Excel exists to carry the records, so they are on by default there.
    expect(screen.getByRole('checkbox', { name: 'Include ticket records' })).toBeChecked()
  })

  it('says why records are missing without tickets.export', () => {
    renderWith(
      <ExportDialog
        open
        onClose={() => {}}
        reportType="ticket_overview"
        params={{}}
        canIncludeRecords={false}
      />,
    )

    expect(screen.queryByRole('checkbox', { name: /records/ })).not.toBeInTheDocument()
    expect(screen.getByText(/needs the tickets export permission/)).toBeInTheDocument()
  })

  it('prepares the file with the report filters, then downloads it once when ready', async () => {
    const ready = exportItem({
      status: 'completed',
      status_label: 'Ready',
      filename: 'ticket-overview_month_20261018-1000.pdf',
      download_url: '/api/admin/reports/exports/e-1/download',
    })
    api.createExport.mockResolvedValue(exportItem())
    api.fetchExport
      .mockResolvedValueOnce(exportItem({ status: 'processing' }))
      .mockResolvedValue(ready)

    renderWith(
      <ExportDialog
        open
        onClose={() => {}}
        reportType="ticket_overview"
        params={{ period: 'year', room_type: 'laboratory' }}
        canIncludeRecords
      />,
    )
    await userEvent.click(screen.getByRole('button', { name: 'Prepare file' }))

    expect(api.createExport).toHaveBeenCalledWith(
      expect.objectContaining({
        report_type: 'ticket_overview',
        params: { period: 'year', room_type: 'laboratory' },
        format: 'pdf',
        paper: 'a4',
        orientation: 'portrait',
        include_records: false,
      }),
    )
    expect(await screen.findByText('Your file is ready', {}, { timeout: 4000 })).toBeInTheDocument()
    await waitFor(() => expect(api.downloadExport).toHaveBeenCalledTimes(1))
    expect(api.downloadExport).toHaveBeenCalledWith(ready)
  })

  it('says so when the file could not be prepared', async () => {
    api.createExport.mockResolvedValue(exportItem())
    api.fetchExport.mockResolvedValue(exportItem({ status: 'failed', status_label: 'Failed' }))

    renderWith(
      <ExportDialog
        open
        onClose={() => {}}
        reportType="ticket_overview"
        params={{}}
        canIncludeRecords
      />,
    )
    await userEvent.click(screen.getByRole('button', { name: 'Prepare file' }))

    expect(await screen.findByText('The file could not be prepared')).toBeInTheDocument()
    expect(api.downloadExport).not.toHaveBeenCalled()
  })
})

describe('EmailReportDialog', () => {
  const check: RecipientCheck = {
    recipients: [
      { address: 'head@sccit.local', external: false },
      { address: 'auditor@example.org', external: true },
    ],
    external: ['auditor@example.org'],
    internal_domains: ['sccit.local'],
    delivers_externally: false,
    daily_cap: 50,
    sent_today: 0,
    remaining_today: 50,
    contents: {
      report: 'Ticket overview',
      format: 'PDF',
      includes: ['The summary, headline figures, charts and their tables'],
      excludes: ['No ticket titles, descriptions, comments or notes'],
    },
  }

  it('checks, asks to confirm outside addresses, and sends exactly those', async () => {
    api.checkRecipients.mockResolvedValue(check)
    api.sendReportEmail.mockResolvedValue(
      exportItem({
        purpose: 'email',
        profile: 'identifiers',
        deliveries: [
          {
            recipient: 'head@sccit.local',
            external: false,
            status: 'queued',
            status_label: 'Queued',
            sent_at: null,
          },
          {
            recipient: 'auditor@example.org',
            external: true,
            status: 'queued',
            status_label: 'Queued',
            sent_at: null,
          },
        ],
      }),
    )
    api.fetchExport.mockResolvedValue(
      exportItem({
        status: 'completed',
        deliveries: [
          {
            recipient: 'head@sccit.local',
            external: false,
            status: 'sent',
            status_label: 'Sent to mail server',
            sent_at: 'x',
          },
          {
            recipient: 'auditor@example.org',
            external: true,
            status: 'sent',
            status_label: 'Sent to mail server',
            sent_at: 'x',
          },
        ],
      }),
    )

    renderWith(
      <EmailReportDialog
        open
        onClose={() => {}}
        reportType="ticket_overview"
        params={{ period: 'month' }}
        canIncludeRecords
      />,
    )

    await userEvent.type(
      screen.getByRole('textbox', { name: 'Recipients' }),
      'Head@sccit.local, auditor@example.org',
    )
    expect(screen.getByRole('list', { name: 'Recipients' })).toHaveTextContent('head@sccit.local')
    await userEvent.click(screen.getByRole('button', { name: 'Continue' }))

    expect(
      await screen.findByText('This server captures outgoing email for testing'),
    ).toBeInTheDocument()
    expect(screen.getByText('Outside the school')).toBeInTheDocument()
    expect(
      screen.getByText('No ticket titles, descriptions, comments or notes'),
    ).toBeInTheDocument()

    const sendButton = screen.getByRole('button', { name: 'Send to 2 recipients' })
    expect(sendButton).toBeDisabled()
    await userEvent.click(screen.getByRole('checkbox', { name: /this address outside the school/ }))
    await userEvent.click(sendButton)

    expect(api.sendReportEmail).toHaveBeenCalledWith(
      expect.objectContaining({
        recipients: ['head@sccit.local', 'auditor@example.org'],
        confirmed_external: ['auditor@example.org'],
        format: 'pdf',
      }),
    )
    expect(await screen.findAllByText('Sent to mail server', {}, { timeout: 4000 })).toHaveLength(2)
    expect(screen.queryByText(/delivered/i)).not.toBeInTheDocument()
  })

  it('refuses more than ten addresses before asking the server', async () => {
    renderWith(
      <EmailReportDialog
        open
        onClose={() => {}}
        reportType="ticket_overview"
        params={{}}
        canIncludeRecords
      />,
    )
    const many = Array.from({ length: 11 }, (_, i) => `s${i}@sccit.local`).join(',')
    await userEvent.type(screen.getByRole('textbox', { name: 'Recipients' }), many)

    expect(screen.getByText('Send to at most 10 addresses at a time.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled()
    expect(api.checkRecipients).not.toHaveBeenCalled()
  })

  it('offers no CSV for email', () => {
    renderWith(
      <EmailReportDialog
        open
        onClose={() => {}}
        reportType="ticket_overview"
        params={{}}
        canIncludeRecords
      />,
    )
    expect(screen.queryByRole('radio', { name: /CSV/ })).not.toBeInTheDocument()
  })
})

describe('ReportActions', () => {
  it('offers nothing without reports.export', () => {
    access.value = { canView: true, canExport: false, canExportRecords: false }
    renderWith(
      <ReportActions reportType="ticket_overview" params={{}} printPath="/app/dashboard/print" />,
    )

    expect(screen.queryByRole('button', { name: 'Export' })).not.toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Print' })).not.toBeInTheDocument()
  })

  it('links Print to the print view with the same filters', () => {
    renderWith(
      <ReportActions
        reportType="ticket_overview"
        params={{ period: 'year', status: 'open' }}
        printPath="/app/dashboard/print"
      />,
    )

    const href = screen.getByRole('link', { name: 'Print' }).getAttribute('href') ?? ''
    expect(href).toMatch(/^\/app\/dashboard\/print\?/)
    const query = new URLSearchParams(href.split('?')[1])
    expect(query.get('period')).toBe('year')
    expect(query.get('status')).toBe('open')
    expect(query.get('autoprint')).toBe('1')
    expect(query.get('include_records')).toBe('1')
  })
})

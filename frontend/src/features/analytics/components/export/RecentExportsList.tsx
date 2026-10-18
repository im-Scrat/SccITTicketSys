import { useState } from 'react'
import { Button } from '@/components/ui'
import { formatDateTime } from '@/lib/datetime'
import { downloadExport } from '../../api/reportsApi'
import { useRecentExports } from '../../hooks/exports'

/**
 * The viewer's own report files from the last week, newest first — a
 * download that was missed can be fetched again until the file expires.
 * Emailed copies are listed with their delivery statuses ("Sent to mail
 * server", never "delivered").
 */
export function RecentExportsList({ enabled }: { enabled: boolean }) {
  const recent = useRecentExports(enabled)
  const [error, setError] = useState<string | null>(null)

  if (recent.isLoading) return <p className="text-sm text-muted">Loading…</p>
  if (recent.isError)
    return <p className="text-sm text-muted">Recent exports could not be loaded.</p>
  if (!recent.data || recent.data.length === 0) {
    return <p className="text-sm text-muted">No exports in the last week.</p>
  }

  return (
    <div className="flex flex-col gap-2">
      <ul className="flex flex-col divide-y divide-border">
        {recent.data.map((item) => (
          <li key={item.id} className="flex flex-wrap items-center justify-between gap-3 py-2">
            <span className="flex min-w-0 flex-col">
              <span className="text-sm font-medium text-ink break-words">
                {item.report_label} · {item.format_label}
                {item.purpose === 'email' ? ' · emailed' : ''}
              </span>
              <span className="text-xs text-muted">
                {formatDateTime(item.created_at)} · {item.status_label}
                {item.deliveries && item.deliveries.length > 0
                  ? ` · ${item.deliveries.map((d) => `${d.recipient}: ${d.status_label}`).join(', ')}`
                  : ''}
              </span>
            </span>
            {item.download_url && (
              <Button
                variant="ghost"
                size="sm"
                onClick={() =>
                  downloadExport(item).catch((e: unknown) =>
                    setError(e instanceof Error ? e.message : 'The download did not start.'),
                  )
                }
              >
                Download
              </Button>
            )}
          </li>
        ))}
      </ul>
      {error && <p className="text-sm text-warning-strong">{error}</p>}
    </div>
  )
}

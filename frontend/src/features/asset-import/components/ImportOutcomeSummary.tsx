import { Download } from 'lucide-react'
import { useState } from 'react'
import {
  Alert,
  Badge,
  Button,
  ButtonLink,
  Skeleton,
  StatCard,
  Table,
  TBody,
  Td,
  Th,
  THead,
  Tr,
} from '@/components/ui'
import { downloadOriginal } from '../api/assetImportApi'
import { useRetryImport } from '../hooks/mutations'
import { useImportRows } from '../hooks/queries'
import { failureText, plural } from '../lib/steps'
import type { AssetImport } from '../types'
import { ImportPanel } from './ImportPanel'

const BANNER_TONE = { success: 'success', warning: 'warning', neutral: 'info' } as const

/**
 * Summary (Phase 2.10 R6, owner decision D3): the outcome exactly as the
 * server worded it — never re-derived here — the counts behind it, and every
 * row that did not become a record, with its reason and a way to retry.
 */
export function ImportOutcomeSummary({ item }: { item: AssetImport }) {
  const retry = useRetryImport(item.id)
  const [downloadError, setDownloadError] = useState<string | null>(null)
  const failed = useImportRows(item.id, { outcome: 'failed', per_page: 100 })
  const notAttempted = useImportRows(item.id, { outcome: 'not_attempted', per_page: 100 })
  const skipped = useImportRows(item.id, { outcome: 'skipped_duplicate', per_page: 100 })
  const result = item.result

  if (!result) {
    return (
      <Alert tone="info" title={item.status_label}>
        {item.status === 'cancelled'
          ? 'This import was cancelled before anything was saved.'
          : 'This import is no longer open.'}
      </Alert>
    )
  }

  const notSaved = [
    ...(failed.data?.data ?? []),
    ...(notAttempted.data?.data ?? []),
    ...(skipped.data?.data ?? []),
  ].sort((a, b) => a.row_number - b.row_number)

  return (
    <div className="flex flex-col gap-8">
      <Alert tone={BANNER_TONE[result.tone]} title={result.headline}>
        {result.detail}
      </Alert>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="PC units created" value={result.created_pc_units.toLocaleString()} />
        <StatCard label="Other equipment created" value={result.created_assets.toLocaleString()} />
        <StatCard
          label="Duplicates skipped"
          value={result.skipped_duplicates.toLocaleString()}
          hint="Existing records unchanged"
        />
        <StatCard
          label="Not saved"
          value={(result.failed + result.not_attempted).toLocaleString()}
          hint={result.failed + result.not_attempted > 0 ? 'Listed below' : undefined}
        />
      </div>
      <p className="text-sm text-muted">
        {result.excluded > 0 ? `${plural(result.excluded, 'row')} excluded by you. ` : ''}
        {result.catalog_created > 0
          ? `${plural(result.catalog_created, 'catalog entry', 'catalog entries')} added. `
          : ''}
      </p>

      {(failed.isLoading || notAttempted.isLoading) && <Skeleton className="h-32 w-full" />}
      {notSaved.length > 0 && (
        <ImportPanel
          id="import-not-saved"
          title="Rows not imported"
          description="Nothing here changed any existing record."
          actions={
            item.can.retry ? (
              <Button
                size="sm"
                variant="secondary"
                disabled={retry.isPending}
                onClick={() => retry.mutate()}
              >
                Fix and retry
              </Button>
            ) : undefined
          }
        >
          <Table>
            <THead>
              <tr>
                <Th className="w-20">Row</Th>
                <Th>Identifier</Th>
                <Th>Result</Th>
                <Th>Why</Th>
              </tr>
            </THead>
            <TBody>
              {notSaved.map((row) => (
                <Tr key={row.row_number}>
                  <Td className="tabular-nums">{row.row_number}</Td>
                  <Td className="whitespace-nowrap font-mono text-sm">
                    {String(
                      row.values.unit_code ??
                        row.values.asset_tag ??
                        row.original.unit_code ??
                        row.original.asset_tag ??
                        '—',
                    )}
                  </Td>
                  <Td>
                    <Badge tone={row.outcome === 'skipped_duplicate' ? 'info' : 'warning'}>
                      {row.outcome_label}
                    </Badge>
                  </Td>
                  <Td className="text-sm text-muted">{failureText(row.failure_code)}</Td>
                </Tr>
              ))}
            </TBody>
          </Table>
        </ImportPanel>
      )}

      <div className="flex flex-wrap gap-3">
        <ButtonLink to="/app/assets/list" variant="secondary">
          Open the asset directory
        </ButtonLink>
        {item.can.download_file && (
          <Button
            variant="ghost"
            onClick={() => {
              setDownloadError(null)
              downloadOriginal(item.id, item.file.extension).catch((error: Error) =>
                setDownloadError(error.message),
              )
            }}
            leftIcon={<Download size={24} aria-hidden="true" />}
          >
            Download the original file
          </Button>
        )}
      </div>
      {downloadError && <Alert tone="error">{downloadError}</Alert>}
    </div>
  )
}

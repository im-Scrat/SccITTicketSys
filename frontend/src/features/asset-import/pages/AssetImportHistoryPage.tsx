import { FileSpreadsheet, Upload } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import {
  ButtonLink,
  EmptyState,
  ErrorState,
  Pagination,
  Skeleton,
  Table,
  TBody,
  Td,
  Th,
  THead,
  Tr,
} from '@/components/ui'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { ImportPageHeader } from '../components/ImportPageHeader'
import { ImportStatusBadge } from '../components/ImportStatusBadge'
import { useAssetImports } from '../hooks/queries'

/**
 * Every asset import (Phase 2.10 R6): what it was, where it got to, and its
 * outcome in the server's own words. Any administrator may open any of them.
 */
export default function AssetImportHistoryPage() {
  useDocumentMeta({ title: 'Asset imports' })
  const [page, setPage] = useState(1)
  const imports = useAssetImports(page)

  return (
    <div className="mx-auto flex w-full max-w-[96rem] flex-col gap-8">
      <ImportPageHeader
        title="Asset imports"
        description="Spreadsheets imported into the asset register, newest first."
      >
        <ButtonLink to="/app/assets/import" leftIcon={<Upload size={24} aria-hidden="true" />}>
          New import
        </ButtonLink>
      </ImportPageHeader>

      {imports.isLoading ? (
        <Skeleton className="h-64 w-full" />
      ) : imports.isError ? (
        <ErrorState
          title="The imports could not be loaded"
          onRetry={() => void imports.refetch()}
        />
      ) : imports.data && imports.data.data.length > 0 ? (
        <div className="flex flex-col gap-4">
          <Table>
            <THead>
              <tr>
                <Th>File</Th>
                <Th>Holds</Th>
                <Th>Where it is</Th>
                <Th className="text-right">Rows</Th>
                <Th>Started</Th>
              </tr>
            </THead>
            <TBody>
              {imports.data.data.map((item) => (
                <Tr key={item.id}>
                  <Td className="max-w-[22rem]">
                    <Link
                      to={`/app/assets/imports/${item.id}`}
                      className="break-words font-semibold text-primary-strong hover:underline"
                    >
                      {item.file_name}
                    </Link>
                  </Td>
                  <Td>{item.entity_mode_label}</Td>
                  <Td>
                    <span className="flex flex-col items-start gap-1">
                      <ImportStatusBadge
                        status={item.status}
                        statusLabel={item.status_label}
                        outcome={item.outcome}
                      />
                      {item.headline && <span className="text-sm text-muted">{item.headline}</span>}
                    </span>
                  </Td>
                  <Td className="text-right tabular-nums">{item.total_rows.toLocaleString()}</Td>
                  <Td className="text-sm text-muted">
                    {item.created_at ? new Date(item.created_at).toLocaleString() : '—'}
                    {item.created_by ? ` · ${item.created_by}` : ''}
                  </Td>
                </Tr>
              ))}
            </TBody>
          </Table>
          <Pagination
            page={imports.data.meta.current_page}
            lastPage={imports.data.meta.last_page}
            total={imports.data.meta.total}
            from={imports.data.meta.from}
            to={imports.data.meta.to}
            onPage={setPage}
          />
        </div>
      ) : (
        <EmptyState
          icon={<FileSpreadsheet size={32} aria-hidden="true" />}
          title="No imports yet"
          description="Import PC units and other equipment from an Excel or CSV file."
          action={<ButtonLink to="/app/assets/import">Start an import</ButtonLink>}
        />
      )}
    </div>
  )
}

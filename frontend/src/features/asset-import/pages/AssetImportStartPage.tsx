import { isAxiosError } from 'axios'
import { Download, History } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Alert, Button, ButtonLink, Dropzone, ProgressBar } from '@/components/ui'
import { useDocumentMeta } from '@/hooks/useDocumentMeta'
import { downloadTemplate } from '../api/assetImportApi'
import { EntityModeChooser } from '../components/EntityModeChooser'
import { ImportPageHeader } from '../components/ImportPageHeader'
import { ImportPanel } from '../components/ImportPanel'
import { ImportStatusBadge } from '../components/ImportStatusBadge'
import { useUploadImport } from '../hooks/mutations'
import { useAssetImports } from '../hooks/queries'
import type { ImportMode } from '../types'

const MAX_BYTES = 10 * 1024 * 1024

const TEMPLATE_LABEL: Record<ImportMode, string> = {
  pc_units: 'PC units template',
  assets: 'Other equipment template',
  mixed: 'Combined template',
}

/**
 * Start an asset import (Phase 2.10 R6, SRS FR-AST-016): what the file holds,
 * the file itself, and the template. Nothing is saved to the register here —
 * the file is read and checked, and the administrator reviews every row
 * before anything is created.
 */
export default function AssetImportStartPage() {
  useDocumentMeta({ title: 'Import assets' })
  const navigate = useNavigate()
  const [mode, setMode] = useState<ImportMode>('pc_units')
  const [file, setFile] = useState<File | null>(null)
  const [progress, setProgress] = useState<number | null>(null)
  const [templateError, setTemplateError] = useState<string | null>(null)
  const upload = useUploadImport()
  const recent = useAssetImports(1)

  const serverError = isAxiosError(upload.error)
    ? ((
        upload.error.response?.data as
          { errors?: Record<string, string[]>; message?: string } | undefined
      )?.errors?.file?.[0] ??
      (upload.error.response?.data as { message?: string } | undefined)?.message ??
      'The file could not be uploaded. Try again.')
    : upload.error
      ? 'The file could not be uploaded. Try again.'
      : undefined

  const start = () => {
    if (!file) return
    setProgress(0)
    upload.mutate(
      { file, mode, onProgress: setProgress },
      {
        onSuccess: (created) => void navigate(`/app/assets/imports/${created.id}`),
        onSettled: () => setProgress(null),
      },
    )
  }

  const template = (which: ImportMode) => {
    setTemplateError(null)
    downloadTemplate(which).catch((error: Error) => setTemplateError(error.message))
  }

  return (
    <div className="mx-auto flex w-full max-w-[96rem] flex-col gap-8">
      <ImportPageHeader
        title="Import assets"
        description="Add PC units and other equipment from a spreadsheet. Nothing is saved until you review the rows and confirm."
      >
        <ButtonLink
          to="/app/assets/imports"
          variant="secondary"
          leftIcon={<History size={24} aria-hidden="true" />}
        >
          Import history
        </ButtonLink>
      </ImportPageHeader>

      <div className="grid gap-8 xl:grid-cols-12">
        <ImportPanel id="import-file" title="Choose a file" className="xl:col-span-8">
          <div className="flex flex-col gap-6">
            <EntityModeChooser value={mode} onChange={setMode} disabled={upload.isPending} />

            <Dropzone
              accept={['.xlsx', '.csv']}
              maxBytes={MAX_BYTES}
              file={file}
              onFile={(chosen) => {
                upload.reset()
                setFile(chosen)
              }}
              label="Spreadsheet"
              hint="Excel (.xlsx) or CSV, up to 10 MB and 5,000 rows."
              disabled={upload.isPending}
              error={serverError}
            />

            {progress !== null && (
              <ProgressBar
                label={progress < 1 ? 'Uploading' : 'Reading the file'}
                value={progress < 1 ? Math.round(progress * 100) : undefined}
              />
            )}

            <div className="flex flex-wrap items-center justify-end gap-3">
              <Button onClick={start} disabled={!file || upload.isPending}>
                {upload.isPending ? 'Uploading…' : 'Upload and read'}
              </Button>
            </div>
          </div>
        </ImportPanel>

        <div className="flex flex-col gap-8 xl:col-span-4">
          <ImportPanel
            id="import-template"
            title="Start from the template"
            description="A file built from the template maps every column by itself."
          >
            <div className="flex flex-col gap-3">
              {(['pc_units', 'assets', 'mixed'] as ImportMode[]).map((which) => (
                <Button
                  key={which}
                  variant="secondary"
                  onClick={() => template(which)}
                  className="justify-start"
                  leftIcon={<Download size={24} aria-hidden="true" />}
                >
                  {TEMPLATE_LABEL[which]}
                </Button>
              ))}
              {templateError && <Alert tone="error">{templateError}</Alert>}
            </div>
          </ImportPanel>

          <ImportPanel id="import-recent" title="Recent imports">
            {recent.data && recent.data.data.length > 0 ? (
              <ul className="flex flex-col divide-y divide-border">
                {recent.data.data.slice(0, 5).map((item) => (
                  <li key={item.id} className="flex flex-col gap-1 py-3 first:pt-0 last:pb-0">
                    <Link
                      to={`/app/assets/imports/${item.id}`}
                      className="break-words text-sm font-semibold text-primary-strong hover:underline"
                    >
                      {item.file_name}
                    </Link>
                    <span className="flex flex-wrap items-center gap-2 text-xs text-muted">
                      <ImportStatusBadge
                        status={item.status}
                        statusLabel={item.status_label}
                        outcome={item.outcome}
                        outcomeLabel={item.headline}
                      />
                      {item.created_at && new Date(item.created_at).toLocaleString()}
                    </span>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-sm text-muted">No imports yet.</p>
            )}
          </ImportPanel>
        </div>
      </div>
    </div>
  )
}

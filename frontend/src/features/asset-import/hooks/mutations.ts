import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  bulkRows,
  cancelImport,
  confirmImport,
  decideCatalog,
  decideLocation,
  retryImport,
  updateMapping,
  updateRow,
  updateSource,
  uploadImport,
  type CatalogDecision,
  type LocationDecision,
  type RowChange,
  type SourceOptions,
} from '../api/assetImportApi'
import type { AssetImport, ImportMode, MappingPayload } from '../types'
import { importKeys } from './queries'

/**
 * Every change to a draft answers with the import as it now is; it replaces
 * the cached one, and the import's rows, locations and catalog are refetched,
 * because one decision can change the status of rows on every tab.
 */
function useApplyImport(id: string) {
  const client = useQueryClient()
  return (next: AssetImport) => {
    client.setQueryData(importKeys.detail(id), next)
    void client.invalidateQueries({
      queryKey: [...importKeys.detail(id)],
      predicate: (q) => q.queryKey.length > 3,
    })
    void client.invalidateQueries({ queryKey: ['asset-imports', 'list'] })
  }
}

export function useUploadImport() {
  const client = useQueryClient()
  return useMutation({
    mutationFn: ({
      file,
      mode,
      onProgress,
    }: {
      file: File
      mode: ImportMode
      onProgress?: (f: number) => void
    }) => uploadImport(file, mode, onProgress),
    onSuccess: (created) => {
      client.setQueryData(importKeys.detail(created.id), created)
      void client.invalidateQueries({ queryKey: ['asset-imports', 'list'] })
    },
  })
}

export function useUpdateSource(id: string) {
  const apply = useApplyImport(id)
  return useMutation({
    mutationFn: (options: SourceOptions) => updateSource(id, options),
    onSuccess: apply,
  })
}

export function useUpdateMapping(id: string) {
  const apply = useApplyImport(id)
  return useMutation({
    mutationFn: (payload: MappingPayload) => updateMapping(id, payload),
    onSuccess: apply,
  })
}

export function useUpdateRow(id: string) {
  const apply = useApplyImport(id)
  return useMutation({
    mutationFn: ({ rowNumber, change }: { rowNumber: number; change: RowChange }) =>
      updateRow(id, rowNumber, change),
    onSuccess: (result) => apply(result.import),
  })
}

export function useBulkRows(id: string) {
  const apply = useApplyImport(id)
  return useMutation({
    mutationFn: ({ rows, exclude }: { rows: number[]; exclude: boolean }) =>
      bulkRows(id, rows, exclude),
    onSuccess: (result) => apply(result.import),
  })
}

export function useDecideLocation(id: string) {
  const apply = useApplyImport(id)
  return useMutation({
    mutationFn: ({ location, decision }: { location: string; decision: LocationDecision }) =>
      decideLocation(id, location, decision),
    onSuccess: (result) => apply(result.import),
  })
}

export function useDecideCatalog(id: string) {
  const apply = useApplyImport(id)
  return useMutation({
    mutationFn: ({ entry, decision }: { entry: string; decision: CatalogDecision }) =>
      decideCatalog(id, entry, decision),
    onSuccess: (result) => apply(result.import),
  })
}

export function useConfirmImport(id: string) {
  const apply = useApplyImport(id)
  return useMutation({
    mutationFn: ({
      revision,
      expected,
    }: {
      revision: number
      expected: { pc_units: number; assets: number; catalog_entries: number }
    }) => confirmImport(id, revision, expected),
    onSuccess: apply,
  })
}

export function useCancelImport(id: string) {
  const apply = useApplyImport(id)
  return useMutation({ mutationFn: () => cancelImport(id), onSuccess: apply })
}

export function useRetryImport(id: string) {
  const apply = useApplyImport(id)
  return useMutation({ mutationFn: () => retryImport(id), onSuccess: apply })
}

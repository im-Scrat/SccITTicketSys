import { keepPreviousData, useQuery } from '@tanstack/react-query'
import {
  fetchCatalog,
  fetchImport,
  fetchImports,
  fetchLocations,
  fetchRoomOptions,
  fetchRows,
  type RowQuery,
} from '../api/assetImportApi'
import type { ImportStatus } from '../types'

export const importKeys = {
  all: ['asset-imports'] as const,
  list: (page: number) => ['asset-imports', 'list', page] as const,
  detail: (id: string) => ['asset-imports', 'detail', id] as const,
  rows: (id: string, query: RowQuery) => ['asset-imports', 'detail', id, 'rows', query] as const,
  locations: (id: string) => ['asset-imports', 'detail', id, 'locations'] as const,
  catalog: (id: string) => ['asset-imports', 'detail', id, 'catalog'] as const,
  rooms: (search: string) => ['asset-imports', 'rooms', search] as const,
}

/** The stages where the server is working and the page should follow it. */
const WORKING: ImportStatus[] = ['reading', 'validating', 'queued', 'importing']

export function isWorking(status: ImportStatus | undefined): boolean {
  return status !== undefined && WORKING.includes(status)
}

/** One import; polled while the server reads, checks or saves it. */
export function useAssetImport(id: string) {
  return useQuery({
    queryKey: importKeys.detail(id),
    queryFn: () => fetchImport(id),
    refetchInterval: (query) => (isWorking(query.state.data?.status) ? 1500 : false),
  })
}

export function useAssetImports(page: number) {
  return useQuery({
    queryKey: importKeys.list(page),
    queryFn: () => fetchImports(page),
    placeholderData: keepPreviousData,
  })
}

export function useImportRows(id: string, query: RowQuery, enabled = true) {
  return useQuery({
    queryKey: importKeys.rows(id, query),
    queryFn: () => fetchRows(id, query),
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function useImportLocations(id: string, enabled = true) {
  return useQuery({
    queryKey: importKeys.locations(id),
    queryFn: () => fetchLocations(id),
    enabled,
  })
}

export function useImportCatalog(id: string, enabled = true) {
  return useQuery({ queryKey: importKeys.catalog(id), queryFn: () => fetchCatalog(id), enabled })
}

export function useRoomOptions(search: string, enabled = true) {
  return useQuery({
    queryKey: importKeys.rooms(search),
    queryFn: () => fetchRoomOptions(search),
    placeholderData: keepPreviousData,
    staleTime: 60_000,
    enabled,
  })
}

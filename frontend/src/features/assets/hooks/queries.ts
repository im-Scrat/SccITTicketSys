import { keepPreviousData, useQuery } from '@tanstack/react-query'
import {
  type AssetKind,
  fetchAsset,
  fetchAssetDashboard,
  fetchAudit,
  fetchCatalog,
  fetchHistory,
  fetchPcUnit,
  fetchQrCodes,
  listAssets,
  listAttachments,
  listPcUnits,
} from '../api/assetsApi'
import type { AssetParams, PcUnitParams } from '../types'

export const assetsKeys = {
  all: ['assets'] as const,
  dashboard: () => ['assets', 'dashboard'] as const,
  catalog: () => ['assets', 'catalog'] as const,
  list: (params: AssetParams) => ['assets', 'list', params] as const,
  detail: (id: string) => ['assets', 'detail', id] as const,
  pcUnits: (params: PcUnitParams) => ['assets', 'pc-units', params] as const,
  pcUnit: (id: string) => ['assets', 'pc-unit', id] as const,
  history: (kind: AssetKind, id: string, page: number) =>
    ['assets', 'history', kind, id, page] as const,
  audit: (kind: AssetKind, id: string, page: number) =>
    ['assets', 'audit', kind, id, page] as const,
  qr: (kind: AssetKind, id: string) => ['assets', 'qr', kind, id] as const,
  attachments: (kind: AssetKind, id: string) => ['assets', 'attachments', kind, id] as const,
}

export function useAssetDashboard() {
  return useQuery({ queryKey: assetsKeys.dashboard(), queryFn: fetchAssetDashboard })
}

/** The form vocabularies change rarely; hold them for the session. */
export function useAssetCatalog(enabled = true) {
  return useQuery({
    queryKey: assetsKeys.catalog(),
    queryFn: fetchCatalog,
    staleTime: 5 * 60 * 1000,
    enabled,
  })
}

export function useAssetsList(params: AssetParams, enabled = true) {
  return useQuery({
    queryKey: assetsKeys.list(params),
    queryFn: () => listAssets(params),
    placeholderData: keepPreviousData, // smooth page/filter transitions
    enabled,
  })
}

export function useAsset(id: string | undefined) {
  return useQuery({
    queryKey: assetsKeys.detail(id ?? ''),
    queryFn: () => fetchAsset(id as string),
    enabled: Boolean(id),
  })
}

export function usePcUnitsList(params: PcUnitParams, enabled = true) {
  return useQuery({
    queryKey: assetsKeys.pcUnits(params),
    queryFn: () => listPcUnits(params),
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function usePcUnit(id: string | undefined) {
  return useQuery({
    queryKey: assetsKeys.pcUnit(id ?? ''),
    queryFn: () => fetchPcUnit(id as string),
    enabled: Boolean(id),
  })
}

export function useAssetHistory(kind: AssetKind, id: string | undefined, page = 1) {
  return useQuery({
    queryKey: assetsKeys.history(kind, id ?? '', page),
    queryFn: () => fetchHistory(kind, id as string, page),
    enabled: Boolean(id),
    placeholderData: keepPreviousData,
  })
}

export function useAssetAudit(kind: AssetKind, id: string | undefined, page = 1) {
  return useQuery({
    queryKey: assetsKeys.audit(kind, id ?? '', page),
    queryFn: () => fetchAudit(kind, id as string, page),
    enabled: Boolean(id),
    placeholderData: keepPreviousData,
  })
}

export function useQrCodes(kind: AssetKind, id: string | undefined, enabled = true) {
  return useQuery({
    queryKey: assetsKeys.qr(kind, id ?? ''),
    queryFn: () => fetchQrCodes(kind, id as string),
    enabled: Boolean(id) && enabled,
  })
}

export function useAttachments(kind: AssetKind, id: string | undefined, enabled = true) {
  return useQuery({
    queryKey: assetsKeys.attachments(kind, id ?? ''),
    queryFn: () => listAttachments(kind, id as string),
    enabled: Boolean(id) && enabled,
  })
}

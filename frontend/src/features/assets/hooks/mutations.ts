import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  archiveAsset,
  archivePcUnit,
  type AssetKind,
  type AssetPayload,
  assignTechnician,
  changeAssetStatus,
  createAsset,
  createPcUnit,
  deleteAttachment,
  generateQr,
  type PcUnitPayload,
  regenerateQr,
  restoreAsset,
  restorePcUnit,
  revokeQr,
  transferAsset,
  updateAsset,
  updatePcUnit,
  updateSpecification,
  uploadAttachment,
} from '../api/assetsApi'
import type { AssetStatusValue, PcSpecification } from '../types'
import { assetsKeys } from './queries'

/**
 * Invalidate the whole assets namespace. Any write can move several views at
 * once — a status change alters the directory, the dashboard counts, the detail
 * payload and the timeline together — so a namespace-wide invalidation is both
 * the simplest and the correct choice, and the queries are small enough that
 * refetching them costs little. Same reasoning as the Locations slice.
 */
function useInvalidateAssets() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: assetsKeys.all })
}

export function useCreateAsset() {
  const invalidate = useInvalidateAssets()
  return useMutation({
    mutationFn: (payload: AssetPayload) => createAsset(payload),
    onSuccess: () => void invalidate(),
  })
}

export function useUpdateAsset(id: string) {
  const invalidate = useInvalidateAssets()
  return useMutation({
    mutationFn: (payload: Partial<AssetPayload>) => updateAsset(id, payload),
    onSuccess: () => void invalidate(),
  })
}

export function useChangeAssetStatus(id: string) {
  const invalidate = useInvalidateAssets()
  return useMutation({
    mutationFn: ({ status, reason }: { status: AssetStatusValue; reason?: string }) =>
      changeAssetStatus(id, status, reason),
    onSuccess: () => void invalidate(),
  })
}

export function useTransferAsset(id: string) {
  const invalidate = useInvalidateAssets()
  return useMutation({
    mutationFn: ({
      room,
      reason,
      remarks,
    }: {
      room: string | null
      reason?: string
      remarks?: string
    }) => transferAsset(id, room, reason, remarks),
    onSuccess: () => void invalidate(),
  })
}

export function useAssignTechnician(id: string) {
  const invalidate = useInvalidateAssets()
  return useMutation({
    mutationFn: (technician: string | null) => assignTechnician(id, technician),
    onSuccess: () => void invalidate(),
  })
}

export function useArchiveAsset() {
  const invalidate = useInvalidateAssets()
  return useMutation({
    mutationFn: (id: string) => archiveAsset(id),
    onSuccess: () => void invalidate(),
  })
}

export function useRestoreAsset() {
  const invalidate = useInvalidateAssets()
  return useMutation({
    mutationFn: (id: string) => restoreAsset(id),
    onSuccess: () => void invalidate(),
  })
}

export function useCreatePcUnit() {
  const invalidate = useInvalidateAssets()
  return useMutation({
    mutationFn: (payload: PcUnitPayload) => createPcUnit(payload),
    onSuccess: () => void invalidate(),
  })
}

export function useUpdatePcUnit(id: string) {
  const invalidate = useInvalidateAssets()
  return useMutation({
    mutationFn: (payload: Partial<PcUnitPayload>) => updatePcUnit(id, payload),
    onSuccess: () => void invalidate(),
  })
}

export function useUpdateSpecification(id: string) {
  const invalidate = useInvalidateAssets()
  return useMutation({
    mutationFn: (payload: Partial<PcSpecification>) => updateSpecification(id, payload),
    onSuccess: () => void invalidate(),
  })
}

export function useArchivePcUnit() {
  const invalidate = useInvalidateAssets()
  return useMutation({
    mutationFn: (id: string) => archivePcUnit(id),
    onSuccess: () => void invalidate(),
  })
}

export function useRestorePcUnit() {
  const invalidate = useInvalidateAssets()
  return useMutation({
    mutationFn: (id: string) => restorePcUnit(id),
    onSuccess: () => void invalidate(),
  })
}

export function useGenerateQr(kind: AssetKind, id: string) {
  const invalidate = useInvalidateAssets()
  return useMutation({
    mutationFn: () => generateQr(kind, id),
    onSuccess: () => void invalidate(),
  })
}

export function useRegenerateQr(kind: AssetKind, id: string) {
  const invalidate = useInvalidateAssets()
  return useMutation({
    mutationFn: () => regenerateQr(kind, id),
    onSuccess: () => void invalidate(),
  })
}

export function useRevokeQr(kind: AssetKind, id: string) {
  const invalidate = useInvalidateAssets()
  return useMutation({
    mutationFn: () => revokeQr(kind, id),
    onSuccess: () => void invalidate(),
  })
}

export function useUploadAttachment(kind: AssetKind, id: string) {
  const invalidate = useInvalidateAssets()
  return useMutation({
    mutationFn: ({ file, caption }: { file: File; caption?: string }) =>
      uploadAttachment(kind, id, file, caption),
    onSuccess: () => void invalidate(),
  })
}

export function useDeleteAttachment() {
  const invalidate = useInvalidateAssets()
  return useMutation({
    mutationFn: (attachmentId: string) => deleteAttachment(attachmentId),
    onSuccess: () => void invalidate(),
  })
}

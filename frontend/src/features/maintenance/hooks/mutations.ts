import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  addMaintenanceNote,
  archiveMaintenance,
  changeMaintenanceStatus,
  createMaintenance,
  deleteEvidence,
  type MaintenancePayload,
  reassignMaintenance,
  recordReplacement,
  type ReplacementPayload,
  restoreMaintenance,
  toggleChecklistItem,
  updateMaintenance,
  uploadEvidence,
} from '../api/maintenanceApi'
import type { EvidenceStage } from '../types'
import { maintenanceKeys } from './queries'

/**
 * Invalidate the whole maintenance namespace.
 *
 * Any write moves several views at once — completing a visit alters the queue,
 * the history list, the detail payload, the preventive horizon and the module
 * dashboard together — so a namespace-wide invalidation is both simplest and
 * correct. Same reasoning as the Tickets and Assets slices.
 */
function useInvalidateMaintenance() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: maintenanceKeys.all })
}

export function useCreateMaintenance() {
  const invalidate = useInvalidateMaintenance()
  return useMutation({
    mutationFn: (payload: MaintenancePayload) => createMaintenance(payload),
    onSuccess: () => void invalidate(),
  })
}

export function useUpdateMaintenance(id: string) {
  const invalidate = useInvalidateMaintenance()
  return useMutation({
    mutationFn: (payload: Partial<MaintenancePayload> & { reschedule_reason?: string }) =>
      updateMaintenance(id, payload),
    onSuccess: () => void invalidate(),
  })
}

export function useChangeMaintenanceStatus(id: string) {
  const invalidate = useInvalidateMaintenance()
  return useMutation({
    mutationFn: ({
      status,
      resolution,
      reason,
    }: {
      status: string
      resolution?: string
      reason?: string
    }) => changeMaintenanceStatus(id, status, { resolution, reason }),
    onSuccess: () => void invalidate(),
  })
}

export function useReassignMaintenance(id: string) {
  const invalidate = useInvalidateMaintenance()
  return useMutation({
    mutationFn: (technician: string) => reassignMaintenance(id, technician),
    onSuccess: () => void invalidate(),
  })
}

export function useArchiveMaintenance(id: string) {
  const invalidate = useInvalidateMaintenance()
  return useMutation({
    mutationFn: () => archiveMaintenance(id),
    onSuccess: () => void invalidate(),
  })
}

export function useRestoreMaintenance(id: string) {
  const invalidate = useInvalidateMaintenance()
  return useMutation({
    mutationFn: () => restoreMaintenance(id),
    onSuccess: () => void invalidate(),
  })
}

export function useToggleChecklistItem(recordId: string) {
  const invalidate = useInvalidateMaintenance()
  return useMutation({
    mutationFn: ({
      itemId,
      isCompleted,
      remarks,
    }: {
      itemId: number
      isCompleted: boolean
      remarks?: string
    }) => toggleChecklistItem(recordId, itemId, isCompleted, remarks),
    // Ticking a required item can unblock completion, so the detail payload —
    // which carries `available_transitions` — has to be refetched, not patched.
    onSuccess: () => void invalidate(),
  })
}

export function useUploadEvidence(recordId: string) {
  const invalidate = useInvalidateMaintenance()
  return useMutation({
    mutationFn: ({
      file,
      imageType,
      caption,
    }: {
      file: File
      imageType: EvidenceStage
      caption?: string
    }) => uploadEvidence(recordId, file, imageType, caption),
    onSuccess: () => void invalidate(),
  })
}

export function useDeleteEvidence(recordId: string) {
  const invalidate = useInvalidateMaintenance()
  return useMutation({
    mutationFn: (imageId: string) => deleteEvidence(recordId, imageId),
    onSuccess: () => void invalidate(),
  })
}

export function useAddMaintenanceNote(recordId: string) {
  const invalidate = useInvalidateMaintenance()
  return useMutation({
    mutationFn: (body: string) => addMaintenanceNote(recordId, body),
    onSuccess: () => void invalidate(),
  })
}

export function useRecordReplacement(recordId: string) {
  const invalidate = useInvalidateMaintenance()
  return useMutation({
    mutationFn: (payload: ReplacementPayload) => recordReplacement(recordId, payload),
    onSuccess: () => void invalidate(),
  })
}

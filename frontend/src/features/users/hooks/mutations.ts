import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  archiveUser,
  bulkUserAction,
  type BulkPayload,
  changeUserRole,
  createUser,
  type CreateUserPayload,
  forcePasswordReset,
  type LifecycleAction,
  lifecycleAction,
  resendApproval,
  resendRejection,
  restoreUser,
  sendPasswordReset,
  setUserPermissions,
  unlockAccount,
  updateUser,
  type UpdateUserPayload,
} from '../api/usersApi'
import { usersKeys } from './queries'

/** Invalidate the whole users namespace (list + metrics + detail + audit). */
function useInvalidateUsers() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: usersKeys.all })
}

export function useCreateUser() {
  const invalidate = useInvalidateUsers()
  return useMutation({
    mutationFn: (payload: CreateUserPayload) => createUser(payload),
    onSuccess: () => void invalidate(),
  })
}

export function useUpdateUser(id: string) {
  const invalidate = useInvalidateUsers()
  return useMutation({
    mutationFn: (payload: UpdateUserPayload) => updateUser(id, payload),
    onSuccess: () => void invalidate(),
  })
}

export function useArchiveUser() {
  const invalidate = useInvalidateUsers()
  return useMutation({
    mutationFn: (id: string) => archiveUser(id),
    onSuccess: () => void invalidate(),
  })
}

export function useRestoreUser() {
  const invalidate = useInvalidateUsers()
  return useMutation({
    mutationFn: (id: string) => restoreUser(id),
    onSuccess: () => void invalidate(),
  })
}

export function useLifecycleAction() {
  const invalidate = useInvalidateUsers()
  return useMutation({
    mutationFn: ({
      id,
      action,
      reason,
    }: {
      id: string
      action: LifecycleAction
      reason?: string
    }) => lifecycleAction(id, action, reason),
    onSuccess: () => void invalidate(),
  })
}

export function useChangeRole() {
  const invalidate = useInvalidateUsers()
  return useMutation({
    mutationFn: ({ id, role }: { id: string; role: string }) => changeUserRole(id, role),
    onSuccess: () => void invalidate(),
  })
}

export function useSetPermissions() {
  const invalidate = useInvalidateUsers()
  return useMutation({
    mutationFn: ({ id, grants, denies }: { id: string; grants: string[]; denies: string[] }) =>
      setUserPermissions(id, grants, denies),
    onSuccess: () => void invalidate(),
  })
}

export function useForcePasswordReset() {
  const invalidate = useInvalidateUsers()
  return useMutation({
    mutationFn: ({ id, required }: { id: string; required: boolean }) =>
      forcePasswordReset(id, required),
    onSuccess: () => void invalidate(),
  })
}

export function useUnlockAccount() {
  const invalidate = useInvalidateUsers()
  return useMutation({
    mutationFn: (id: string) => unlockAccount(id),
    onSuccess: () => void invalidate(),
  })
}

export function useSendPasswordReset() {
  return useMutation({ mutationFn: (id: string) => sendPasswordReset(id) })
}

export function useResendApproval() {
  return useMutation({ mutationFn: (id: string) => resendApproval(id) })
}

export function useResendRejection() {
  return useMutation({ mutationFn: (id: string) => resendRejection(id) })
}

export function useBulkAction() {
  const invalidate = useInvalidateUsers()
  return useMutation({
    mutationFn: (payload: BulkPayload) => bulkUserAction(payload),
    onSuccess: () => void invalidate(),
  })
}

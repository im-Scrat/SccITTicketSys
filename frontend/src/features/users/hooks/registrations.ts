import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  approveRegistration,
  listRegistrations,
  type RegistrationParams,
  rejectRegistration,
  updateRegistration,
  type UpdateRegistrationPayload,
} from '../api/registrationsApi'

const KEY = ['registrations'] as const

export function useRegistrations(params: RegistrationParams) {
  return useQuery({
    queryKey: [...KEY, params],
    queryFn: () => listRegistrations(params),
    placeholderData: keepPreviousData,
  })
}

function useInvalidate() {
  const queryClient = useQueryClient()
  return () => {
    void queryClient.invalidateQueries({ queryKey: KEY })
    void queryClient.invalidateQueries({ queryKey: ['users'] }) // metrics/directory
  }
}

export function useApproveRegistration() {
  const invalidate = useInvalidate()
  return useMutation({ mutationFn: (id: string) => approveRegistration(id), onSuccess: invalidate })
}

export function useRejectRegistration() {
  const invalidate = useInvalidate()
  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason?: string }) => rejectRegistration(id, reason),
    onSuccess: invalidate,
  })
}

export function useUpdateRegistration() {
  const invalidate = useInvalidate()
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: UpdateRegistrationPayload }) =>
      updateRegistration(id, payload),
    onSuccess: invalidate,
  })
}

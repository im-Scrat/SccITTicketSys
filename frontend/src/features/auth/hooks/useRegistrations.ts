import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { approveRegistration, listRegistrations, rejectRegistration } from '../api/authApi'

const REGISTRATIONS_KEY = ['registrations'] as const

export function useRegistrations() {
  return useQuery({
    queryKey: REGISTRATIONS_KEY,
    queryFn: listRegistrations,
  })
}

export function useApproveRegistration() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: string) => approveRegistration(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: REGISTRATIONS_KEY })
    },
  })
}

export function useRejectRegistration() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, reason }: { id: string; reason?: string }) => rejectRegistration(id, reason),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: REGISTRATIONS_KEY })
    },
  })
}

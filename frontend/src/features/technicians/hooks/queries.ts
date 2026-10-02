import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { fetchTechnician, fetchTechnicianOverview } from '../api/techniciansApi'

/** Root `['technicians']` — the admin dashboard channel invalidates it on every change. */
export const techniciansKeys = {
  all: ['technicians'] as const,
  overview: () => ['technicians', 'overview'] as const,
  detail: (id: string) => ['technicians', 'detail', id] as const,
}

export function useTechnicianOverview(enabled = true) {
  return useQuery({
    queryKey: techniciansKeys.overview(),
    queryFn: fetchTechnicianOverview,
    placeholderData: keepPreviousData,
    staleTime: 30_000,
    enabled,
  })
}

export function useTechnician(id: string | undefined) {
  return useQuery({
    queryKey: techniciansKeys.detail(id ?? ''),
    queryFn: () => fetchTechnician(id!),
    enabled: Boolean(id),
  })
}

import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { fetchDashboard } from '../api/dashboardApi'

export const dashboardKeys = {
  all: ['dashboard'] as const,
  widgets: () => ['dashboard', 'widgets'] as const,
}

/**
 * The role dashboard. `staleTime` mirrors the server's 30-second cache, so a
 * navigation back to the landing page is instant rather than a refetch, and
 * `keepPreviousData` holds the previous render during a refresh instead of
 * flashing skeletons.
 */
export function useDashboard() {
  return useQuery({
    queryKey: dashboardKeys.widgets(),
    queryFn: fetchDashboard,
    staleTime: 30_000,
    placeholderData: keepPreviousData,
  })
}

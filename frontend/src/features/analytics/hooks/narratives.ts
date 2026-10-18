import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '@/services/api'
import type { NarrativeState, OverallParams, ReportNarrative } from '../types'

const key = (params: OverallParams) => ['overall-report', 'insights', params] as const

/**
 * The recorded facts and the latest AI interpretation for these filters
 * (Phase 2.10 R5). Polled every 2 s only while an interpretation is being
 * generated; never refetched on focus while someone reads it.
 */
export function useNarrativeState(params: OverallParams) {
  return useQuery({
    queryKey: key(params),
    queryFn: async () => {
      const { data } = await api.get<{ data: NarrativeState }>(
        '/admin/reports/narratives/current',
        { params },
      )
      return data.data
    },
    refetchInterval: (query) => (query.state.data?.narrative?.status === 'pending' ? 2000 : false),
    refetchOnWindowFocus: false,
    retry: (failures, error) =>
      (error as { response?: { status?: number } }).response?.status !== 403 && failures < 2,
  })
}

export function useGenerateNarrative(params: OverallParams) {
  const client = useQueryClient()
  return useMutation({
    mutationFn: async () => {
      const { data } = await api.post<{ data: ReportNarrative }>(
        '/admin/reports/narratives',
        params,
      )
      return data.data
    },
    onSuccess: () => void client.invalidateQueries({ queryKey: key(params) }),
  })
}

import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { fetchPrediction, fetchPredictions } from '../api/predictionsApi'
import type { PredictionListParams } from '../types'

export const predictionsKeys = {
  all: ['predictions'] as const,
  list: (params: PredictionListParams) => ['predictions', 'list', params] as const,
  detail: (id: string) => ['predictions', 'detail', id] as const,
}

export function usePredictions(params: PredictionListParams, enabled = true) {
  return useQuery({
    queryKey: predictionsKeys.list(params),
    queryFn: () => fetchPredictions(params),
    placeholderData: keepPreviousData,
    enabled,
  })
}

export function usePrediction(id: string | undefined) {
  return useQuery({
    queryKey: predictionsKeys.detail(id ?? ''),
    queryFn: () => fetchPrediction(id as string),
    enabled: Boolean(id),
  })
}

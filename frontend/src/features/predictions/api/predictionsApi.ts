import { api } from '@/services/api'
import type {
  PredictionDetailEnvelope,
  PredictionListEnvelope,
  PredictionListParams,
} from '../types'

function cleanParams(params: object): Record<string, string | number> {
  const out: Record<string, string | number> = {}
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') out[key] = value as string | number
  }
  return out
}

export async function fetchPredictions(
  params: PredictionListParams,
): Promise<PredictionListEnvelope> {
  const { data } = await api.get<PredictionListEnvelope>('/admin/predictions', {
    params: cleanParams(params),
  })
  return data
}

export async function fetchPrediction(id: string): Promise<PredictionDetailEnvelope> {
  const { data } = await api.get<PredictionDetailEnvelope>(`/admin/predictions/${id}`)
  return data
}

export async function confirmPrediction(id: string): Promise<PredictionDetailEnvelope> {
  const { data } = await api.patch<PredictionDetailEnvelope>(`/admin/predictions/${id}/confirm`)
  return data
}

export async function dismissPrediction(id: string): Promise<PredictionDetailEnvelope> {
  const { data } = await api.patch<PredictionDetailEnvelope>(`/admin/predictions/${id}/dismiss`)
  return data
}

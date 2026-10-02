import { api } from '@/services/api'
import type { AiConnectionCheck, AiSettingsPayload, AiSettingsValues } from '../types'

export async function fetchAiSettings(): Promise<AiSettingsPayload> {
  const { data } = await api.get<{ data: AiSettingsPayload }>('/admin/ai/settings')
  return data.data
}

export async function saveAiSettings(values: AiSettingsValues): Promise<AiSettingsPayload> {
  const { data } = await api.put<{ data: AiSettingsPayload }>('/admin/ai/settings', values)
  return data.data
}

export async function testAiConnection(): Promise<AiConnectionCheck> {
  const { data } = await api.post<{ data: AiConnectionCheck }>('/admin/ai/test')
  return data.data
}

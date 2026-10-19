import { api } from '@/services/api'
import type { AssistantConversation, AssistantConversationSummary, AssistantReply } from '../types'

export async function fetchAssistantStatus(): Promise<{ available: boolean }> {
  const { data } = await api.get<{ data: { available: boolean } }>('/ai/assistant/status')
  return data.data
}

export async function sendAssistantMessage(payload: {
  message: string
  conversation_id?: string | null
}): Promise<AssistantReply> {
  const { data } = await api.post<{ data: AssistantReply }>('/ai/assistant/messages', payload)
  return data.data
}

export async function fetchAssistantConversations(): Promise<AssistantConversationSummary[]> {
  const { data } = await api.get<{ data: AssistantConversationSummary[] }>(
    '/ai/assistant/conversations',
  )
  return data.data
}

export async function fetchAssistantConversation(id: string): Promise<AssistantConversation> {
  const { data } = await api.get<{ data: AssistantConversation }>(
    `/ai/assistant/conversations/${id}`,
  )
  return data.data
}

export async function deleteAssistantConversation(id: string): Promise<void> {
  await api.delete(`/ai/assistant/conversations/${id}`)
}

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  deleteAssistantConversation,
  fetchAssistantConversation,
  fetchAssistantConversations,
  fetchAssistantStatus,
  sendAssistantMessage,
} from '../api/assistantApi'

/**
 * Root `['assistant']`. Every conversation cached here is the signed-in user's
 * own (the server isolates by user), and sign-out clears the whole query
 * cache (useLogout), so nothing here outlives the session that fetched it.
 */
export const assistantKeys = {
  all: ['assistant'] as const,
  status: () => ['assistant', 'status'] as const,
  conversations: () => ['assistant', 'conversations'] as const,
  conversation: (id: string) => ['assistant', 'conversation', id] as const,
}

export function useAssistantStatus(enabled = true) {
  return useQuery({
    queryKey: assistantKeys.status(),
    queryFn: fetchAssistantStatus,
    staleTime: 5 * 60 * 1000,
    retry: false,
    enabled,
  })
}

export function useAssistantConversations(enabled: boolean) {
  return useQuery({
    queryKey: assistantKeys.conversations(),
    queryFn: fetchAssistantConversations,
    enabled,
  })
}

export function useAssistantConversation(id: string | null) {
  return useQuery({
    queryKey: assistantKeys.conversation(id ?? ''),
    queryFn: () => fetchAssistantConversation(id!),
    enabled: Boolean(id),
  })
}

export function useSendAssistantMessage() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: sendAssistantMessage,
    onSuccess: (reply) => {
      void queryClient.invalidateQueries({ queryKey: assistantKeys.conversations() })
      void queryClient.invalidateQueries({
        queryKey: assistantKeys.conversation(reply.conversation_id),
      })
    },
  })
}

export function useDeleteAssistantConversation() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: deleteAssistantConversation,
    onSuccess: (_, id) => {
      queryClient.removeQueries({ queryKey: assistantKeys.conversation(id) })
      void queryClient.invalidateQueries({ queryKey: assistantKeys.conversations() })
    },
  })
}

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { fetchAiSettings, saveAiSettings, testAiConnection } from '../api/aiSettingsApi'

export const aiSettingsKeys = {
  all: ['ai-settings'] as const,
}

export function useAiSettings() {
  return useQuery({ queryKey: aiSettingsKeys.all, queryFn: fetchAiSettings })
}

export function useSaveAiSettings() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: saveAiSettings,
    onSuccess: (payload) => {
      queryClient.setQueryData(aiSettingsKeys.all, payload)
      // The assistant launcher reads its availability from the status endpoint.
      void queryClient.invalidateQueries({ queryKey: ['assistant'] })
    },
  })
}

export function useTestAiConnection() {
  return useMutation({ mutationFn: testAiConnection })
}

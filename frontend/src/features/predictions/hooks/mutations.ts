import { useMutation, useQueryClient } from '@tanstack/react-query'
import { confirmPrediction, dismissPrediction } from '../api/predictionsApi'
import { predictionsKeys } from './queries'

function useInvalidatePredictions() {
  const queryClient = useQueryClient()
  return () => queryClient.invalidateQueries({ queryKey: predictionsKeys.all })
}

export function useConfirmPrediction(id: string) {
  const invalidate = useInvalidatePredictions()
  return useMutation({
    mutationFn: () => confirmPrediction(id),
    onSuccess: () => void invalidate(),
  })
}

export function useDismissPrediction(id: string) {
  const invalidate = useInvalidatePredictions()
  return useMutation({
    mutationFn: () => dismissPrediction(id),
    onSuccess: () => void invalidate(),
  })
}

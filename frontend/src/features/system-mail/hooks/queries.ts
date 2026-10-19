import { useMutation, useQuery } from '@tanstack/react-query'
import { checkMailConnection, fetchMailConfiguration, sendMailTest } from '../api/mailApi'

export const mailKeys = {
  configuration: ['system-mail', 'configuration'] as const,
}

export function useMailConfiguration() {
  return useQuery({ queryKey: mailKeys.configuration, queryFn: fetchMailConfiguration })
}

export function useCheckMailConnection() {
  return useMutation({ mutationFn: checkMailConnection })
}

export function useSendMailTest() {
  return useMutation({ mutationFn: sendMailTest })
}

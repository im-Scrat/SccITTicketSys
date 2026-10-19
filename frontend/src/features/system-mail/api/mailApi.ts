import { api } from '@/services/api'
import type { MailConfiguration, MailConnectionCheck, MailTestResult } from '../types'

export async function fetchMailConfiguration(): Promise<MailConfiguration> {
  const { data } = await api.get<{ data: MailConfiguration }>('/admin/system/mail')
  return data.data
}

export async function checkMailConnection(): Promise<MailConnectionCheck> {
  const { data } = await api.post<{ data: MailConnectionCheck }>('/admin/system/mail/check')
  return data.data
}

/** There is no recipient parameter: the server sends to the signed-in administrator. */
export async function sendMailTest(): Promise<MailTestResult> {
  const { data } = await api.post<{ data: MailTestResult }>('/admin/system/mail/test')
  return data.data
}

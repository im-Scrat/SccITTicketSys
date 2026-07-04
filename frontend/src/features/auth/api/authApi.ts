import { api, initCsrf } from '@/services/api'
import type { AuthUser, LoginPayload, RegisterPayload, RegistrationSummary } from '../types'

/** Fetch the authenticated principal, or null when not signed in (401). */
export async function fetchCurrentUser(): Promise<AuthUser> {
  const { data } = await api.get<{ data: AuthUser }>('/user')
  return data.data
}

export async function login(payload: LoginPayload): Promise<AuthUser> {
  await initCsrf()
  const { data } = await api.post<{ data: AuthUser }>('/login', payload)
  return data.data
}

export async function logout(): Promise<void> {
  await api.post('/logout')
}

export async function register(
  payload: RegisterPayload,
): Promise<{ message: string; status: string }> {
  await initCsrf()
  const { data } = await api.post<{ message: string; status: string }>('/register', payload)
  return data
}

export async function forgotPassword(email: string): Promise<{ message: string }> {
  await initCsrf()
  const { data } = await api.post<{ message: string }>('/forgot-password', { email })
  return data
}

export interface ResetPasswordPayload {
  token: string
  email: string
  password: string
  password_confirmation: string
}

export async function resetPassword(payload: ResetPasswordPayload): Promise<{ message: string }> {
  await initCsrf()
  const { data } = await api.post<{ message: string }>('/reset-password', payload)
  return data
}

export interface ChangePasswordPayload {
  current_password: string
  password: string
  password_confirmation: string
}

export async function changePassword(payload: ChangePasswordPayload): Promise<{ message: string }> {
  const { data } = await api.put<{ message: string }>('/password', payload)
  return data
}

// --- Administrator registration review ---

export async function listRegistrations(): Promise<RegistrationSummary[]> {
  const { data } = await api.get<{ data: RegistrationSummary[] }>('/admin/registrations')
  return data.data
}

export async function approveRegistration(id: string): Promise<RegistrationSummary> {
  const { data } = await api.post<{ data: RegistrationSummary }>(
    `/admin/registrations/${id}/approve`,
  )
  return data.data
}

export async function rejectRegistration(
  id: string,
  reason?: string,
): Promise<RegistrationSummary> {
  const { data } = await api.post<{ data: RegistrationSummary }>(
    `/admin/registrations/${id}/reject`,
    { reason },
  )
  return data.data
}
